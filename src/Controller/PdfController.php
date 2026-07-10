<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Pdf\PdfService;

final class PdfController extends AbstractJsonController
{
    public function __construct(
        private readonly PdfService $pdfService,
    ) {
    }

    #[Route('/generate', name: 'app_generate_pdf', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $payload = $this->decodeJsonPayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $result = $this->pdfService->generate($payload);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/keys', name: 'app_pdf_keys', methods: ['POST'])]
    public function keys(Request $request): JsonResponse
    {
        $filters = $this->decodeJsonPayload($request);
        if ($filters instanceof JsonResponse) {
            return $filters;
        }

        $result = $this->pdfService->findObjectKeys($filters);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/signed-urls', name: 'app_pdf_signed_urls', methods: ['GET'])]
    public function signedUrls(Request $request): JsonResponse
    {
        $result = $this->pdfService->findSignedUrls($request->query->all());

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/pdf/{reference}', name: 'app_pdf_link', methods: ['GET'])]
    public function pdfLink(string $reference): JsonResponse
    {
        $result = $this->pdfService->resolve($reference);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/pdf/{reference}/restore', name: 'app_pdf_restore', methods: ['POST'])]
    public function restore(string $reference): JsonResponse
    {
        $result = $this->pdfService->restore($reference);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/preview/{reference}', name: 'app_pdf_preview', methods: ['GET'])]
    public function preview(string $reference): Response
    {
        $result = $this->pdfService->resolve($reference);

        if (!$result['ok']) {
            return new Response($this->renderPreviewError((string) ($result['body']['error'] ?? 'No se pudo cargar la referencia.')), $result['status_code'], [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $body = $result['body'];
        $pdfUrl = (string) ($body['pdf_url'] ?? '');

        return new Response($this->renderPreviewPage($reference, $body, $pdfUrl), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function renderPreviewPage(string $reference, array $body, string $pdfUrl): string
    {
        $status = htmlspecialchars((string) ($body['status'] ?? 'stored'), ENT_QUOTES, 'UTF-8');
        $generatedAt = htmlspecialchars((string) ($body['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $safePdfUrl = htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8');
        $safeReference = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vista PDF {$safeReference}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            margin: 0;
            padding: 24px;
        }
        .card {
            max-width: 1100px;
            margin: 0 auto;
            background: #111827;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 18px 40px rgba(0,0,0,.3);
        }
        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        .item {
            background: #0b1220;
            border: 1px solid #243247;
            border-radius: 12px;
            padding: 12px 14px;
        }
        .label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 6px;
        }
        .value {
            word-break: break-word;
            font-size: 14px;
            color: #f8fafc;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        a.button {
            display: inline-block;
            background: #38bdf8;
            color: #00111a;
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
        }
        iframe {
            width: 100%;
            height: 78vh;
            border: 1px solid #334155;
            border-radius: 14px;
            background: #fff;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Vista del PDF</h1>
        <p>Referencia: <strong>{$safeReference}</strong></p>
        <div class="actions">
            <a class="button" href="{$safePdfUrl}" target="_blank" rel="noopener">Abrir PDF</a>
        </div>
        <div class="meta">
            <div class="item"><div class="label">Estado</div><div class="value">{$status}</div></div>
            <div class="item"><div class="label">Generado</div><div class="value">{$generatedAt}</div></div>
        </div>
        <iframe src="{$safePdfUrl}" title="PDF {$safeReference}"></iframe>
    </div>
</body>
</html>
HTML;
    }

    private function renderPreviewError(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vista PDF</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 24px;">
    <h1>No se pudo cargar el PDF</h1>
    <p>{$safeMessage}</p>
</body>
</html>
HTML;
    }
}
