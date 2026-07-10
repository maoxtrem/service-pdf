<?php

namespace App\Service\Image;

use App\Entity\ImageDocument;
use App\Repository\ImageDocumentRepository;
use App\Service\Minios\MiniosAdapterInterface;
use App\Service\Shared\ResourceDocumentFinder;
use App\Service\Shared\ResourceReferenceGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class ImageService
{
    public function __construct(
        private readonly ResourceReferenceGenerator $referenceGenerator,
        private readonly ResourceDocumentFinder $documentFinder,
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
            $document = $this->createImageDocument($payload, $imageData);

            return $this->storeImageDocument(
                $payload,
                $document,
                $imageData,
                'stored',
                'Imagen guardada correctamente.',
                201,
                true
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse(400, 'No se pudo procesar la imagen.', $payload, [
                'details' => $exception->getMessage(),
            ]);
        }
    }

    public function update(string $identifier, array $payload): array
    {
        $document = $this->findDocumentByIdentifier($identifier);
        if ($document === null) {
            return $this->errorResponse(404, 'No se encontró la imagen a actualizar.', $payload);
        }

        $validation = $this->validateBasePayload($payload);
        if ($validation !== null) {
            return $validation;
        }

        try {
            $imageData = $this->extractImageData($payload);

            return $this->storeImageDocument(
                $payload,
                $document,
                $imageData,
                'updated',
                'Imagen actualizada correctamente.',
                200,
                false
            );
        } catch (\Throwable $exception) {
            return $this->errorResponse(400, 'No se pudo actualizar la imagen.', $payload, [
                'details' => $exception->getMessage(),
            ]);
        }
    }

    public function delete(string $identifier): array
    {
        $document = $this->findDocumentByIdentifier($identifier);

        if ($document === null) {
            return $this->errorResponse(404, 'No se encontró la imagen a eliminar.', []);
        }

        $deleteResult = $this->miniosAdapter->deleteObject(
            $document->getBucket(),
            $document->getObjectKey()
        );

        if (!($deleteResult['ok'] ?? false)) {
            return $this->errorResponse(502, 'No fue posible eliminar la imagen en MinIO.', [], [
                'details' => $deleteResult['body']['details'] ?? null,
            ]);
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
            return $this->errorResponse(
                400,
                'Los campos "tenant", "usuario" y "entorno" son obligatorios para listar imágenes.',
                $filters
            );
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
            return $this->errorResponse(400, 'Faltan campos obligatorios.', $payload, [
                'missing_fields' => $missingFields,
            ]);
        }

        if (!is_string($payload['tenant']) || trim($payload['tenant']) === '') {
            return $this->errorResponse(400, 'El campo "tenant" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['usuario']) || trim($payload['usuario']) === '') {
            return $this->errorResponse(400, 'El campo "usuario" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['entorno']) || trim($payload['entorno']) === '') {
            return $this->errorResponse(400, 'El campo "entorno" debe ser string no vacío.', $payload);
        }

        if (!is_string($payload['image']) || trim($payload['image']) === '') {
            return $this->errorResponse(400, 'El campo "image" debe ser string no vacío.', $payload);
        }

        if (array_key_exists('mime_type', $payload) && !is_string($payload['mime_type'])) {
            return $this->errorResponse(400, 'El campo "mime_type" debe ser string.', $payload);
        }

        if (array_key_exists('file_name', $payload) && !is_string($payload['file_name'])) {
            return $this->errorResponse(400, 'El campo "file_name" debe ser string.', $payload);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int}
     */
    private function extractImageData(array $payload): array
    {
        $rawImage = trim((string) $payload['image']);
        $rawImage = preg_replace('/^data:[^;]+;base64,/', '', $rawImage) ?? $rawImage;

        $mimeType = isset($payload['mime_type']) && is_string($payload['mime_type']) && trim($payload['mime_type']) !== ''
            ? trim($payload['mime_type'])
            : 'image/png';

        $fileName = isset($payload['file_name']) && is_string($payload['file_name']) && trim($payload['file_name']) !== ''
            ? trim($payload['file_name'])
            : null;

        $extension = $this->resolveExtension($mimeType, $fileName);
        $stream = $this->decodeBase64ToStream($rawImage);

        return [
            'stream' => $stream,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'extension' => $extension,
            'size_bytes' => $this->estimateDecodedSize($rawImage),
        ];
    }

    /**
     * @param array{mime_type:string,file_name:?string,stream:resource,extension:string,size_bytes:int} $imageData
     * @return array<string, mixed>
     */
    private function buildStoredPayload(array $imageData): array
    {
        return [
            'mime_type' => $imageData['mime_type'],
            'file_name' => $imageData['file_name'],
            'size_bytes' => $imageData['size_bytes'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int} $imageData
     */
    private function createImageDocument(array $payload, array $imageData): ImageDocument
    {
        $referenceData = $this->referenceGenerator->generate();
        $objectKey = $this->objectKeyGenerator->generate($imageData['extension']);

        return new ImageDocument(
            $referenceData['value'],
            $referenceData['uuid'],
            (string) $payload['tenant'],
            (string) $payload['usuario'],
            (string) $payload['entorno'],
            $imageData['mime_type'],
            $imageData['file_name'],
            $this->buildStoredPayload($imageData),
            $objectKey,
            $this->imageMinioBucket,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{stream:resource,mime_type:string,file_name:?string,extension:string,size_bytes:int} $imageData
     */
    private function storeImageDocument(
        array $payload,
        ImageDocument $document,
        array $imageData,
        string $status,
        string $message,
        int $statusCode,
        bool $isNewDocument
    ): array {
        $requestPayload = $this->buildStoredPayload($imageData);
        $objectKey = $document->getObjectKey();
        $content = $imageData['stream'];

        $uploadResult = $this->miniosAdapter->putObject(
            $document->getBucket(),
            $objectKey,
            $content,
            $imageData['mime_type']
        );

        if (!($uploadResult['ok'] ?? false)) {
            if (is_resource($content)) {
                fclose($content);
            }

            return $this->errorResponse(
                502,
                $isNewDocument ? 'No fue posible guardar la imagen en MinIO.' : 'No fue posible actualizar la imagen en MinIO.',
                $payload
            );
        }

        if ($isNewDocument) {
            $this->entityManager->persist($document);
        } else {
            $document->replaceImageData(
                $imageData['mime_type'],
                $imageData['file_name'],
                $requestPayload
            );
        }

        $document->markStored();
        $this->entityManager->flush();

        if (is_resource($content)) {
            fclose($content);
        }

        return [
            'ok' => true,
            'status_code' => $statusCode,
            'body' => [
                'status' => $status,
                'message' => $message,
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
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $extraBody
     */
    private function errorResponse(int $statusCode, string $message, array $payload, array $extraBody = []): array
    {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'body' => array_merge([
                'error' => $message,
                'tenant' => $payload['tenant'] ?? null,
                'usuario' => $payload['usuario'] ?? null,
                'entorno' => $payload['entorno'] ?? null,
            ], $extraBody),
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

    /**
     * @return resource
     */
    private function decodeBase64ToStream(string $rawImage)
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo crear el buffer temporal de la imagen.');
        }

        $filter = stream_filter_append($stream, 'convert.base64-decode', STREAM_FILTER_WRITE);
        if ($filter === false) {
            fclose($stream);

            throw new \RuntimeException('No se pudo preparar el decodificador base64.');
        }

        $length = strlen($rawImage);
        $chunkSize = 8192;

        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = substr($rawImage, $offset, $chunkSize);
            if ($chunk === false) {
                continue;
            }

            fwrite($stream, $chunk);
        }

        stream_filter_remove($filter);
        rewind($stream);

        return $stream;
    }

    private function estimateDecodedSize(string $rawImage): int
    {
        $length = strlen($rawImage);
        $padding = 0;

        if ($length >= 2 && str_ends_with($rawImage, '==')) {
            $padding = 2;
        } elseif ($length >= 1 && str_ends_with($rawImage, '=')) {
            $padding = 1;
        }

        return (int) floor(($length * 3) / 4) - $padding;
    }

    private function findDocumentByIdentifier(string $identifier): ?ImageDocument
    {
        /** @var ImageDocument|null $document */
        $document = $this->documentFinder->find(
            $identifier,
            fn(string $uuid): ?ImageDocument => $this->imageDocumentRepository->findByUuid($uuid),
            fn(string $reference): ?ImageDocument => $this->imageDocumentRepository->findByReference($reference),
        );

        return $document;
    }
}
