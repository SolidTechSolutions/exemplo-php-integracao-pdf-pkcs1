<?php
declare(strict_types=1);

/**
 * [EN]    PAdES (PDF) signing — PKCS#1 two-step flow (browser extension / external key).
 *         Step 1: /sign-preparation → returns JSON with hash list + finalNonce.
 *         Step 2: /sign-finalization → receives signed hashes, returns signed PDFs in ZIP.
 * [PT-BR] Assinatura PAdES (PDF) — fluxo PKCS#1 em dois passos (extensão de navegador / chave externa).
 *         Passo 1: /sign-preparation → retorna JSON com lista de hashes + finalNonce.
 *         Passo 2: /sign-finalization → recebe hashes assinados, retorna ZIP com PDFs assinados.
 */

namespace SolidSign;

use GuzzleHttp\Client;
use ZipArchive;


/**
 * [EN]    Rewrites visual-signature config entries in a Guzzle multipart array to the INDEXED
 *         form the API expects: signatureFieldConfig[0]={...} per document (NOT a single
 *         signatureFieldConfig=[{...}], which the API ignores, hiding the visual stamp).
 * [PT-BR] Reescreve as entradas de config de assinatura visual no multipart do Guzzle para a
 *         forma INDEXADA que a API espera: signatureFieldConfig[0]={...} por documento (e NÃO
 *         um único signatureFieldConfig=[{...}], que a API ignora e esconde o carimbo).
 */
function indexFieldConfigs(array $multipart): array
{
    $keys = ['signatureFieldConfig', 'signatureTextConfig', 'signatureQrCodeConfig'];
    $out = [];
    foreach ($multipart as $part) {
        if (!isset($part['name']) || !in_array($part['name'], $keys, true)) { $out[] = $part; continue; }
        $raw = $part['contents'] ?? '';
        if ($raw === '' || $raw === null) { continue; }
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && array_is_list($parsed)) {
            foreach ($parsed as $i => $item) {
                $out[] = ['name' => $part['name'] . "[$i]", 'contents' => is_string($item) ? $item : json_encode($item)];
            }
        } elseif ($parsed !== null) {
            $out[] = ['name' => $part['name'] . '[0]', 'contents' => is_string($parsed) ? $parsed : json_encode($parsed)];
        } else {
            $out[] = ['name' => $part['name'] . '[0]', 'contents' => $raw];
        }
    }
    return $out;
}

class PdfPkcs1Service
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(['http_errors' => false]);
    }

    // ── Step 1: Preparation ───────────────────────────────────────────────────

    /**
     * @param array $params   Text fields: authorization, baseUrl, profile, hashAlgorithm, etc.
     * @param array $files    ['document' => [...], 'signatureImage' => [...]]
     * @return string|null    Raw JSON from SolidSign (to be forwarded to the browser extension)
     */
    public function prepare(array $params, array $files): ?string
    {
        $multipart = [];
        foreach ($files['document'] ?? [] as $i => $f) {
            $multipart[] = ['name' => "document[$i]", 'contents' => $f['content'], 'filename' => $f['filename']];
        }
        foreach ($files['signatureImage'] ?? [] as $i => $f) {
            $multipart[] = ['name' => "signatureImage[$i]", 'contents' => $f['content'], 'filename' => $f['filename']];
        }

        $textFields = ['profile', 'hashAlgorithm', 'policyVersion', 'sigFieldMeasurementUnit',
                       'signatureFieldConfig', 'reason', 'location', 'contact',
                       'signatureFieldName', 'signatureTextConfig', 'mdpPermissionLevel',
                       'passwordsForDecryption', 'documentInfoMetadata', 'signatureQrCodeConfig'];
        foreach ($textFields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $multipart[] = ['name' => $field, 'contents' => $params[$field]];
            }
        }

        $baseUrl = rtrim($params['baseUrl'] ?? '', '/');
        $auth    = $params['authorization'] ?? '';

        $multipart = indexFieldConfigs($multipart);
        $response = $this->client->post("$baseUrl/solidsign/dsig/pdf/pkcs1/sign-preparation", [
            'headers'   => ['Authorization' => $auth],
            'multipart' => $multipart,
        ]);

        if ($response->getStatusCode() >= 400) {
            error_log("SolidSign error {$response->getStatusCode()}: " . $response->getBody());
            return null;
        }
        return (string) $response->getBody();
    }

    // ── Step 2: Finalization ──────────────────────────────────────────────────

    /**
     * @param array $params  Must contain: authorization, baseUrl, signedHashes, finalNonce.
     *                       Optional: originalFileNames[] for ZIP entry naming.
     * @return string|null   Path to the generated ZIP file.
     */
    public function finalize(array $params): ?string
    {
        $multipart = [
            ['name' => 'signedHashes', 'contents' => $params['signedHashes'] ?? ''],
            ['name' => 'finalNonce',   'contents' => $params['finalNonce']   ?? ''],
        ];

        $baseUrl = rtrim($params['baseUrl'] ?? '', '/');
        $auth    = $params['authorization'] ?? '';

        $multipart = indexFieldConfigs($multipart);
        $response = $this->client->post("$baseUrl/solidsign/dsig/pdf/pkcs1/sign-finalization", [
            'headers'   => ['Authorization' => $auth],
            'multipart' => $multipart,
        ]);

        if ($response->getStatusCode() >= 400) {
            error_log("SolidSign error {$response->getStatusCode()}: " . $response->getBody());
            return null;
        }

        $signResp  = json_decode((string) $response->getBody(), true);
        $origNames = $params['originalFileNames'] ?? [];
        $tmpDir    = sys_get_temp_dir() . '/solidsign_out_' . uniqid();
        return $this->downloadAndZip($signResp, $origNames, $auth, $tmpDir, 'signed_pdf');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function downloadAndZip(array $signResp, array $origNames, string $auth, string $outputDir, string $prefix): ?string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $tmpDir = sys_get_temp_dir() . '/solidsign_dl_' . uniqid();
        mkdir($tmpDir, 0777, true);

        foreach ($signResp['documents'] ?? [] as $i => $doc) {
            $selfHref = $doc['_links']['self']['href'] ?? null;
            if ($selfHref === null) {
                foreach ($doc['links'] ?? [] as $link) {
                    if ($link['rel'] === 'self') { $selfHref = $link['href']; break; }
                }
            }
            if ($selfHref === null) continue;

            $dlResp = $this->client->get($selfHref, ['headers' => ['Authorization' => $auth]]);
            if ($dlResp->getStatusCode() >= 400) continue;
            $origName = $origNames[$i] ?? "document_$i.pdf";
            file_put_contents("$tmpDir/signed_$origName", (string) $dlResp->getBody());
        }

        $zipPath = "$outputDir/{$prefix}_" . time() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (glob("$tmpDir/*") ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        foreach (glob("$tmpDir/*") ?: [] as $file) { unlink($file); }
        rmdir($tmpDir);

        return $zipPath;
    }
}
