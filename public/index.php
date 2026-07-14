<?php
declare(strict_types=1);

/**
 * [EN]    PAdES (PDF) signing example — PKCS#1 two-step flow (browser extension).
 *         Setup: composer install && cp .env.example .env  (edit .env)
 *         Run:   php -S 0.0.0.0:8089 -t public
 *         Step 1 — Preparation:  POST http://localhost:8089/api/pdf/sign/preparation
 *         Step 2 — Finalization: POST http://localhost:8089/api/pdf/sign/finalization
 *
 * [PT-BR] Exemplo de assinatura PAdES (PDF) — fluxo PKCS#1 em dois passos (extensão de navegador).
 *         Configurar: composer install && cp .env.example .env  (editar .env)
 *         Executar:   php -S 0.0.0.0:8089 -t public
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use SolidSign\PdfPkcs1Service;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app     = AppFactory::create();
$service = new PdfPkcs1Service();

// ── Step 1: Preparation ─────────────────────────────────────────────────────
$app->post('/api/pdf/sign/preparation', function (Request $request, Response $response) use ($service) {
    $params        = (array) $request->getParsedBody();
    $uploadedFiles = $request->getUploadedFiles();

    $files = ['document' => [], 'signatureImage' => []];
    foreach (['document', 'signatureImage'] as $field) {
        $list = $uploadedFiles[$field] ?? [];
        if (!is_array($list)) $list = [$list];
        foreach ($list as $uf) {
            if ($uf->getError() === UPLOAD_ERR_OK) {
                $files[$field][] = ['content' => (string) $uf->getStream(), 'filename' => $uf->getClientFilename()];
            }
        }
    }

    $json = $service->prepare($params, $files);
    if ($json === null) {
        $response->getBody()->write(json_encode(['error' => 'Preparation failed. Check logs.']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $response->getBody()->write($json);
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Step 2: Finalization ────────────────────────────────────────────────────
$app->post('/api/pdf/sign/finalization', function (Request $request, Response $response) use ($service) {
    $params = (array) $request->getParsedBody();

    // originalFileNames may come as a JSON array string or comma-separated
    if (isset($params['originalFileNames']) && is_string($params['originalFileNames'])) {
        $decoded = json_decode($params['originalFileNames'], true);
        $params['originalFileNames'] = is_array($decoded) ? $decoded : explode(',', $params['originalFileNames']);
    }

    $zipPath = $service->finalize($params);
    if ($zipPath === null) {
        $response->getBody()->write(json_encode(['error' => 'Finalization failed. Check logs.']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    $zipContent = (string) file_get_contents($zipPath);
    @unlink($zipPath);
    $response->getBody()->write($zipContent);
    return $response
        ->withHeader('Content-Type', 'application/zip')
        ->withHeader('Content-Disposition', 'attachment; filename="signed_pdf.zip"');
});

$app->run();
