# 🇧🇷 SolidSign API - Exemplo de Integração: Assinatura PAdES (PDF) com PKCS#1 (extensão de navegador, dois passos) (PHP)

## Requisitos

- PHP (built-in server) (`composer install && cp .env.example .env`)
- Um token JWT válido (`POST /solidsign/auth/token`)
- Front-end de referência (opcional): [`exemplo-react-pdf-pkcs1`](https://github.com/SolidTechSolutions/exemplo-react-pdf-pkcs1)

## Como rodar

```bash
composer install && cp .env.example .env
php -S 0.0.0.0:8089 -t public
```

O serviço sobe em `http://localhost:8089`.

## Como funciona

Este back-end expõe os endpoints abaixo, que recebem um formulário (`multipart/form-data`, CORS liberado) e repassam os dados pra SolidSign API real, devolvendo o resultado:

- `POST /api/pdf/pkcs1/prepare/form`
- `POST /api/pdf/pkcs1/finalize/form`

## Variáveis do formulário

**Passo 1 (prepare)**

| Campo | Significado | Default |
|---|---|---|
| `document[i]` | Documento(s) a assinar (passo 1) | — |
| `authorization` | Token JWT (Bearer) | — |
| `baseUrl` | URL base da SolidSign API | `https://www.solidsign.com.br` |
| `certificate` | Certificado público (PEM base64, sem cabeçalhos) da extensão do navegador | — |
| `profile` | Perfil de assinatura PBAD/ETSI | `ADRB` |
| `hashAlgorithm` | Algoritmo de hash | `SHA256` |

**Passo 2 (finalize)**

| Campo | Significado | Default |
|---|---|---|
| `finalNonce` | Nonce retornado pelo passo 1 | — |
| `signatureValue[i]` | Assinatura(s) PKCS#1 calculada(s) localmente pela extensão | — |

---

# 🇬🇧 SolidSign API - Integration Example: PAdES (PDF) Signing with PKCS#1 (browser extension, two-step) (PHP)

## Requirements

- PHP (built-in server) (`composer install && cp .env.example .env`)
- A valid JWT token (`POST /solidsign/auth/token`)
- Reference front-end (optional): [`exemplo-react-pdf-pkcs1`](https://github.com/SolidTechSolutions/exemplo-react-pdf-pkcs1)

## Running

```bash
composer install && cp .env.example .env
php -S 0.0.0.0:8089 -t public
```

The service starts on `http://localhost:8089`.

## How it works

This backend exposes the endpoints below, which accept a form (`multipart/form-data`, CORS-enabled) and forward the data to the real SolidSign API, returning the result:

- `POST /api/pdf/pkcs1/prepare/form`
- `POST /api/pdf/pkcs1/finalize/form`

## Form fields

**Step 1 (prepare)**

| Field | Meaning | Default |
|---|---|---|
| `document[i]` | Document(s) to sign (step 1) | — |
| `authorization` | JWT (Bearer) token | — |
| `baseUrl` | SolidSign API base URL | `https://www.solidsign.com.br` |
| `certificate` | Public certificate (base64 PEM, no headers) from the browser extension | — |
| `profile` | PBAD/ETSI signature profile | `ADRB` |
| `hashAlgorithm` | Hash algorithm | `SHA256` |

**Step 2 (finalize)**

| Field | Meaning | Default |
|---|---|---|
| `finalNonce` | Nonce returned by step 1 | — |
| `signatureValue[i]` | PKCS#1 signature value(s) computed locally by the extension | — |
