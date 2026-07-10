<?php

namespace App\Service\Image;

use App\Entity\ImageDocument;
use App\Repository\ImageDocumentRepository;
use App\Service\Minios\MiniosAdapterInterface;
use App\Service\Pdf\PdfReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class ImageService
{
    public function __construct(
        private readonly PdfReferenceGenerator $referenceGenerator,
        private readonly ImageObjectKeyGenerator $objectKeyGenerator,
        private readonly ImageDocumentRepository $imageDocumentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MiniosAdapterInterface $miniosAdapter,
        private readonly string $imageMinioBucket,
        private readonly int $minioUrlExpirationHours,
    ) {
    }

    public function create(array $payload): array
    {
        $validation = $this->validateBasePayload($payload);
        if ($validation !== null) {
            return $validation;
        }

        try {
            $imageData = $this->extractImageData($payload);
            $referenceData = $this->referenceGenerator->generate();
            $objectKey = $this->objectKeyGenerator->generate($imageData['extension']);
            $bucket = $this->imageMinioBucket;
            $requestPayload = $this->buildStoredPayload($imageData);

            $uploadResult = $this->miniosAdapter->putObject(
                $bucket,
                $objectKey,
                $imageData['binary'],
                $imageData['mime_type']
            );

            if (!($uploadResult['ok'] ?? false)) {
                return $this->errorResponse(
                    502,
                    'No fue posible guardar la imagen en MinIO.',
                    $payload
                );
            }

            $document = new ImageDocument(
                $referenceData['value'],
                $referenceData['uuid'],
                (string) $payload['tenant'],
                (string) $payload['usuario'],
                (string) $payload['entorno'],
                $imageData['mime_type'],
                $imageData['file_name'],
                $requestPayload,
                $objectKey,
                $bucket,
            );
            $document->markStored();

            $this->entityManager->persist($document);
            $this->entityManager->flush();

            return [
                'ok' => true,
                'status_code' => 201,
                'body' => [
                    'status' => 'stored',
                    'message' => 'Imagen guardada correctamente.',
                    'reference' => $document->getReference(),
                    'uuid' => $document->getUuid(),
                    'tenant' => $document->getTenant(),
                    'usuario' => $document->getUsuario(),
                    'entorno' => $document->getEntorno(),
                    'image_url' => $this->miniosAdapter->temporaryObjectUrl(
                        $document->getBucket(),
                        $document->getObjectKey(),
                        $this->minioUrlExpirationHours
                    ),
                    'image_url_expires_in_hours' => $this->minioUrlExpirationHours,
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'No se pudo procesar la imagen.',
                    'details' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function update(string $identifier, array $payload): array
    {
        $document = $this->findDocumentByIdentifier($identifier);
        if ($document === null) {
            return [
                'ok' => false,
                'status_code' => 404,
                'body' => [
                    'error' => 'No se encontró la imagen a actualizar.',
                ],
            ];
        }

        $validation = $this->validateBasePayload($payload);
        if ($validation !== null) {
            return $validation;
        }

        try {
            $imageData = $this->extractImageData($payload);
            $requestPayload = $this->buildStoredPayload($imageData);

            $uploadResult = $this->miniosAdapter->putObject(
                $document->getBucket(),
                $document->getObjectKey(),
                $imageData['binary'],
                $imageData['mime_type']
            );

            if (!($uploadResult['ok'] ?? false)) {
                return $this->errorResponse(
                    502,
                    'No fue posible actualizar la imagen en MinIO.',
                    $payload
                );
            }

            $document->replaceImageData(
                $imageData['mime_type'],
                $imageData['file_name'],
                $requestPayload
            );
            $document->markStored();

            $this->entityManager->flush();

            return [
                'ok' => true,
                'status_code' => 200,
                'body' => [
                    'status' => 'updated',
                    'message' => 'Imagen actualizada correctamente.',
                    'reference' => $document->getReference(),
                    'uuid' => $document->getUuid(),
                    'tenant' => $document->getTenant(),
                    'usuario' => $document->getUsuario(),
                    'entorno' => $document->getEntorno(),
                    'image_url' => $this->miniosAdapter->temporaryObjectUrl(
                        $document->getBucket(),
                        $document->getObjectKey(),
                        $this->minioUrlExpirationHours
                    ),
                    'image_url_expires_in_hours' => $this->minioUrlExpirationHours,
                ],
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'No se pudo actualizar la imagen.',
                    'details' => $exception->getMessage(),
                ],
            ];
        }
    }

    public function delete(string $identifier): array
    {
        $document = $this->findDocumentByIdentifier($identifier);

        if ($document === null) {
            return [
                'ok' => false,
                'status_code' => 404,
                'body' => [
                    'error' => 'No se encontró la imagen a eliminar.',
                ],
            ];
        }

        $deleteResult = $this->miniosAdapter->deleteObject(
            $document->getBucket(),
            $document->getObjectKey()
        );

        if (!($deleteResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible eliminar la imagen en MinIO.',
                    'details' => $deleteResult['body']['details'] ?? null,
                ],
            ];
        }

        $document->markDeleted();
        $this->entityManager->flush();

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'status' => 'deleted',
                'message' => 'Imagen eliminada correctamente.',
                'reference' => $document->getReference(),
                'uuid' => $document->getUuid(),
                'tenant' => $document->getTenant(),
                'usuario' => $document->getUsuario(),
                'entorno' => $document->getEntorno(),
            ],
        ];
    }

    /**
     * @param array{tenant?: mixed, usuario?: mixed, entorno?: mixed, limit?: mixed} $filters
     */
    public function list(array $filters): array
    {
        $tenant = isset($filters['tenant']) && is_string($filters['tenant']) ? trim($filters['tenant']) : '';
        $usuario = isset($filters['usuario']) && is_string($filters['usuario']) ? trim($filters['usuario']) : '';
        $entorno = isset($filters['entorno']) && is_string($filters['entorno']) ? trim($filters['entorno']) : '';
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;

        if ($tenant === '' || $usuario === '' || $entorno === '') {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'Los campos "tenant", "usuario" y "entorno" son obligatorios para listar imágenes.',
                ],
            ];
        }

        $documents = $this->imageDocumentRepository->findByFilters($usuario, $entorno, $tenant, $limit);
        $records = array_map(
            function (ImageDocument $document): array {
                return [
                    'reference' => $document->getReference(),
                    'uuid' => $document->getUuid(),
                    'tenant' => $document->getTenant(),
                    'usuario' => $document->getUsuario(),
                    'entorno' => $document->getEntorno(),
                    'status' => $document->getStatus(),
                    'image_file_name' => $document->getImageFileName(),
                    'image_mime_type' => $document->getImageMimeType(),
                    'image_url' => $this->miniosAdapter->temporaryObjectUrl(
                        $document->getBucket(),
                        $document->getObjectKey(),
                        $this->minioUrlExpirationHours
                    ),
                ];
            },
            $documents
        );

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'message' => 'Imágenes obtenidas desde la base de datos.',
                'filters' => [
                    'tenant' => $tenant,
                    'usuario' => $usuario,
                    'entorno' => $entorno,
                    'limit' => $limit,
                ],
                'count' => count($records),
                'records' => $records,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateBasePayload(array $payload): ?array
    {
        $missingFields = [];

        foreach (['tenant', 'usuario', 'entorno', 'image'] as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                $missingFields[] = $field;
            }
        }

        if ($missingFields !== []) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'Faltan campos obligatorios.',
                    'missing_fields' => $missingFields,
                ],
            ];
        }

        if (!is_string($payload['tenant']) || trim($payload['tenant']) === '') {
            return $this->invalidFieldResponse('tenant', 'string no vacío');
        }

        if (!is_string($payload['usuario']) || trim($payload['usuario']) === '') {
            return $this->invalidFieldResponse('usuario', 'string no vacío');
        }

        if (!is_string($payload['entorno']) || trim($payload['entorno']) === '') {
            return $this->invalidFieldResponse('entorno', 'string no vacío');
        }

        if (!is_string($payload['image']) || trim($payload['image']) === '') {
            return $this->invalidFieldResponse('image', 'string no vacío');
        }

        if (array_key_exists('mime_type', $payload) && !is_string($payload['mime_type'])) {
            return $this->invalidFieldResponse('mime_type', 'string');
        }

        if (array_key_exists('file_name', $payload) && !is_string($payload['file_name'])) {
            return $this->invalidFieldResponse('file_name', 'string');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{binary:string,mime_type:string,file_name:?string,extension:string}
     */
    private function extractImageData(array $payload): array
    {
        $rawImage = trim((string) $payload['image']);
        $rawImage = preg_replace('/^data:[^;]+;base64,/', '', $rawImage) ?? $rawImage;

        $binary = base64_decode($rawImage, true);
        if ($binary === false) {
            throw new \RuntimeException('La imagen debe ser una cadena base64 válida.');
        }

        $mimeType = isset($payload['mime_type']) && is_string($payload['mime_type']) && trim($payload['mime_type']) !== ''
            ? trim($payload['mime_type'])
            : 'image/png';

        $fileName = isset($payload['file_name']) && is_string($payload['file_name']) && trim($payload['file_name']) !== ''
            ? trim($payload['file_name'])
            : null;

        $extension = $this->resolveExtension($mimeType, $fileName);

        return [
            'binary' => $binary,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'extension' => $extension,
        ];
    }

    /**
     * @param array{mime_type:string,file_name:?string,binary:string,extension:string} $imageData
     * @return array<string, mixed>
     */
    private function buildStoredPayload(array $imageData): array
    {
        return [
            'mime_type' => $imageData['mime_type'],
            'file_name' => $imageData['file_name'],
            'size_bytes' => strlen($imageData['binary']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function errorResponse(int $statusCode, string $message, array $payload): array
    {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'body' => [
                'error' => $message,
                'tenant' => $payload['tenant'] ?? null,
                'usuario' => $payload['usuario'] ?? null,
                'entorno' => $payload['entorno'] ?? null,
            ],
        ];
    }

    private function invalidFieldResponse(string $field, string $expected): array
    {
        return [
            'ok' => false,
            'status_code' => 400,
            'body' => [
                'error' => sprintf('El campo "%s" debe ser %s.', $field, $expected),
            ],
        ];
    }

    private function resolveExtension(string $mimeType, ?string $fileName): string
    {
        if ($fileName !== null && pathinfo($fileName, PATHINFO_EXTENSION) !== '') {
            return strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        }

        return match (strtolower($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    private function findDocumentByIdentifier(string $identifier): ?ImageDocument
    {
        if ($this->isValidUuid($identifier)) {
            return $this->imageDocumentRepository->findByUuid($identifier);
        }

        if ($this->isValidReference($identifier)) {
            return $this->imageDocumentRepository->findByReference($identifier);
        }

        return null;
    }

    private function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{32}-[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $reference) === 1;
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid) === 1;
    }
}
