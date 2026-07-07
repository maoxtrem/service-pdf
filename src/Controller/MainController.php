<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MainController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'service-pdf',
            'description' => 'Microservicio encargado de generar documentos PDF.',
            'status' => 'ok',
        ]);
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
        ]);
    }
}
