<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractJsonController
{
    /**
     * @return array<string, mixed>|JsonResponse
     */
    protected function decodeJsonPayload(Request $request): array|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'error' => 'El body debe ser un JSON válido.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $payload;
    }
}
