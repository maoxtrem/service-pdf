<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
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
}
