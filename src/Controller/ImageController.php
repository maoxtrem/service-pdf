<?php

namespace App\Controller;

use App\Service\Image\ImageService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ImageController
{
    public function __construct(
        private readonly ImageService $imageService,
    ) {
    }

    #[Route('/images', name: 'app_images_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'error' => 'El body debe ser un JSON válido.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = $this->imageService->create($payload);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/images/{identifier}', name: 'app_images_update', methods: ['PUT'])]
    public function update(string $identifier, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse([
                'error' => 'El body debe ser un JSON válido.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $result = $this->imageService->update($identifier, $payload);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/images/{identifier}', name: 'app_images_delete', methods: ['DELETE'])]
    public function delete(string $identifier): JsonResponse
    {
        $result = $this->imageService->delete($identifier);

        return new JsonResponse($result['body'], $result['status_code']);
    }

    #[Route('/images', name: 'app_images_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $result = $this->imageService->list($request->query->all());

        return new JsonResponse($result['body'], $result['status_code']);
    }
}
