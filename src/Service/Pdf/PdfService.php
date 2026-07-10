<?php

namespace App\Service\Pdf;

use App\Entity\PdfDocument;
use App\Repository\PdfDocumentRepository;
use App\Service\Minios\MiniosAdapterInterface;
use Doctrine\ORM\EntityManagerInterface;

final class PdfService
{
    public function __construct(
        private readonly PdfHtmlValidator $htmlValidator,
        private readonly PdfReferenceGenerator $referenceGenerator,
        private readonly PdfObjectKeyGenerator $objectKeyGenerator,
        private readonly PdfDocumentBuilder $documentBuilder,
        private readonly PdfDocumentRepository $pdfDocumentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MiniosAdapterInterface $miniosAdapter,
        private readonly string $minioBucket,
        private readonly int $minioUrlExpirationHours,
    ) {
    }

    public function generate(array $payload): array
    {
        $requiredFields = ['tenant', 'usuario', 'entorno', 'html'];
        $missingFields = [];

        foreach ($requiredFields as $field) {
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

        if (!is_string($payload['entorno']) || trim($payload['entorno']) === '') {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'El campo "entorno" debe ser un string no vacío.',
                ],
            ];
        }

        if (!is_string($payload['html'])) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'El campo "html" debe ser un string.',
                ],
            ];
        }

        if (array_key_exists('json', $payload) && !is_array($payload['json'])) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'El campo "json" debe ser un objeto JSON cuando se envía.',
                ],
            ];
        }

        $referenceData = $this->referenceGenerator->generate();
        $reference = $referenceData['value'];
        $jsonData = is_array($payload['json'] ?? null) ? $payload['json'] : [];
        $paperSize = (string) ($payload['paper_size'] ?? 'A4');
        $orientation = (string) ($payload['orientation'] ?? 'portrait');
        $storedPayload = [
            'json' => $jsonData,
            'paper_size' => $paperSize,
            'orientation' => $orientation,
        ];
        $objectKey = $this->objectKeyGenerator->generate();

        try {
            $renderedDocument = $this->renderDocument(
                (string) $payload['html'],
                $jsonData,
                $paperSize,
                $orientation
            );
            $renderedHtml = $renderedDocument['html'];
            $pdfBinary = $renderedDocument['pdf'];
        } catch (\Throwable $exception) {
            if (str_starts_with($exception->getMessage(), 'HTML_VALIDATION:')) {
                return [
                    'ok' => false,
                    'status_code' => 400,
                    'body' => [
                        'error' => 'El HTML renderizado no es válido.',
                        'details' => array_filter(
                            explode(' | ', substr($exception->getMessage(), strlen('HTML_VALIDATION:')))
                        ),
                    ],
                ];
            }

            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'No se pudo renderizar la plantilla Twig.',
                    'details' => $exception->getMessage(),
                ],
            ];
        }
        $bucket = $this->minioBucket;

        $uploadResult = $this->miniosAdapter->putObject($bucket, $objectKey, $pdfBinary, 'application/pdf');
        $pdfUrl = $this->miniosAdapter->temporaryObjectUrl($bucket, $objectKey, $this->minioUrlExpirationHours);

        $miniosContext = [
            'attempted' => true,
            'ok' => $uploadResult['ok'] ?? false,
            'status_code' => $uploadResult['status_code'] ?? 0,
            'body' => $uploadResult['body'] ?? [],
        ];

        if (!$miniosContext['ok']) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible guardar el PDF en MinIO.',
                    'pdf_url' => $pdfUrl,
                    'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
                    'tenant' => $payload['tenant'],
                    'usuario' => $payload['usuario'],
                    'entorno' => $payload['entorno'],
                ],
            ];
        }

        try {
            $document = new PdfDocument(
                $reference,
                $referenceData['uuid'],
                (string) $payload['tenant'],
                (string) $payload['usuario'],
                (string) $payload['entorno'],
                (string) $payload['html'],
                $storedPayload,
                $objectKey,
                $bucket,
            );
            $document->markProcessed();

            $this->entityManager->persist($document);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status_code' => 500,
                'body' => [
                    'error' => 'El PDF fue guardado en MinIO, pero no se pudo registrar en la base de datos.',
                    'pdf_url' => $pdfUrl,
                    'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
                    'tenant' => $payload['tenant'],
                    'usuario' => $payload['usuario'],
                    'entorno' => $payload['entorno'],
                ],
            ];
        }

        return [
            'ok' => true,
            'status_code' => 201,
            'body' => [
                'status' => 'stored',
                'message' => 'Payload recibido correctamente. El PDF fue generado y guardado en MinIO.',
                'tenant' => $payload['tenant'],
                'usuario' => $payload['usuario'],
                'entorno' => $payload['entorno'],
                'pdf_url' => $pdfUrl,
                'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
            ],
        ];
    }

    public function restore(string $identifier): array
    {
        $document = $this->findDocumentByIdentifier($identifier);

        if ($document === null) {
            return [
                'ok' => false,
                'status_code' => 404,
                'body' => [
                    'error' => 'No se encontró un PDF asociado a este identificador.',
                ],
            ];
        }

        $storedPayload = $document->getRequestPayload();
        $jsonData = $this->extractStoredJsonData($storedPayload);
        $paperSize = $this->extractStoredPaperSize($storedPayload);
        $orientation = $this->extractStoredOrientation($storedPayload);

        try {
            $renderedDocument = $this->renderDocument(
                $document->getHtmlContent(),
                $jsonData,
                $paperSize,
                $orientation
            );
            $pdfBinary = $renderedDocument['pdf'];
        } catch (\Throwable $exception) {
            if (str_starts_with($exception->getMessage(), 'HTML_VALIDATION:')) {
                return [
                    'ok' => false,
                    'status_code' => 400,
                    'body' => [
                        'error' => 'El HTML almacenado no es válido.',
                        'details' => array_filter(
                            explode(' | ', substr($exception->getMessage(), strlen('HTML_VALIDATION:')))
                        ),
                    ],
                ];
            }

            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'No se pudo reconstruir el PDF desde la base de datos.',
                    'details' => $exception->getMessage(),
                ],
            ];
        }

        $uploadResult = $this->miniosAdapter->putObject(
            $document->getBucket(),
            $document->getObjectKey(),
            $pdfBinary,
            'application/pdf'
        );

        if (!($uploadResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible sobrescribir el PDF en MinIO.',
                    'tenant' => $document->getTenant(),
                    'usuario' => $document->getUsuario(),
                    'entorno' => $document->getEntorno(),
                ],
            ];
        }

        $document->markProcessed();
        $this->entityManager->flush();

        $pdfUrl = $this->miniosAdapter->temporaryObjectUrl(
            $document->getBucket(),
            $document->getObjectKey(),
            $this->minioUrlExpirationHours
        );

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'status' => 'restored',
                'message' => 'El PDF fue reconstruido desde la base de datos y actualizado en MinIO.',
                'reference' => $document->getReference(),
                'uuid' => $document->getUuid(),
                'tenant' => $document->getTenant(),
                'usuario' => $document->getUsuario(),
                'entorno' => $document->getEntorno(),
                'pdf_url' => $pdfUrl,
                'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
            ],
        ];
    }

    public function resolve(string $identifier): array
    {
        $document = $this->findDocumentByIdentifier($identifier);

        if ($document === null) {
            return [
                'ok' => false,
                'status_code' => 404,
                'body' => [
                    'error' => 'No se encontró un PDF asociado a este identificador.',
                ],
            ];
        }

        $pdfUrl = $this->miniosAdapter->temporaryObjectUrl(
            $document->getBucket(),
            $document->getObjectKey(),
            $this->minioUrlExpirationHours
        );

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'status' => $document->getStatus(),
                'reference' => $document->getReference(),
                'uuid' => $document->getUuid(),
                'message' => 'La solicitud ya cuenta con un PDF almacenado en MinIO.',
                'generated_at' => $document->getProcessedAt()?->format(DATE_ATOM),
                'tenant' => $document->getTenant(),
                'usuario' => $document->getUsuario(),
                'entorno' => $document->getEntorno(),
                'pdf_url' => $pdfUrl,
            ],
        ];
    }

    /**
     * @param array{tenant?: mixed, usuario?: mixed, entorno?: mixed, limit?: mixed} $filters
     */
    public function findObjectKeys(array $filters): array
    {
        $usuario = isset($filters['usuario']) ? trim((string) $filters['usuario']) : '';
        $entorno = isset($filters['entorno']) ? trim((string) $filters['entorno']) : '';
        $tenant = isset($filters['tenant']) && is_string($filters['tenant']) ? trim($filters['tenant']) : null;
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;

        if ($usuario === '' || $entorno === '') {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'Los campos "usuario" y "entorno" son obligatorios para buscar keys.',
                ],
            ];
        }

        $documents = $this->pdfDocumentRepository->findByFilters($usuario, $entorno, $tenant, $limit);
        $records = array_map(
            function (PdfDocument $document): array {
                return [
                    'uuid' => $document->getUuid(),
                    'reference' => $document->getReference(),
                    'tenant' => $document->getTenant(),
                    'usuario' => $document->getUsuario(),
                    'entorno' => $document->getEntorno(),
                    'pdf_url' => $this->miniosAdapter->temporaryObjectUrl(
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
                'message' => 'Keys obtenidas desde la base de datos.',
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
     * @param array{tenant?: mixed, entorno?: mixed, limit?: mixed} $filters
     */
    public function findSignedUrls(array $filters): array
    {
        $tenant = isset($filters['tenant']) && is_string($filters['tenant']) ? trim($filters['tenant']) : '';
        $entorno = isset($filters['entorno']) && is_string($filters['entorno']) ? trim($filters['entorno']) : '';
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;

        if ($tenant === '' || $entorno === '') {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'Los campos "tenant" y "entorno" son obligatorios para buscar URLs firmadas.',
                ],
            ];
        }

        $documents = $this->pdfDocumentRepository->findByTenantAndEntorno($tenant, $entorno, $limit);
        $records = array_map(
            function (PdfDocument $document): array {
                return [
                    'uuid' => $document->getUuid(),
                    'pdf_url' => $this->miniosAdapter->temporaryObjectUrl(
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
                'message' => 'URLs firmadas obtenidas desde la base de datos.',
                'filters' => [
                    'tenant' => $tenant,
                    'entorno' => $entorno,
                    'limit' => $limit,
                ],
                'count' => count($records),
                'records' => $records,
            ],
        ];
    }

    private function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{32}-[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $reference) === 1;
    }

    private function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid) === 1;
    }

    private function findDocumentByIdentifier(string $identifier): ?PdfDocument
    {
        if ($this->isValidUuid($identifier)) {
            return $this->pdfDocumentRepository->findByUuid($identifier);
        }

        if ($this->isValidReference($identifier)) {
            return $this->pdfDocumentRepository->findByReference($identifier);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $jsonData
     * @return array{html:string,pdf:string}
     */
    private function renderDocument(string $template, array $jsonData, string $paperSize, string $orientation): array
    {
        $context = $this->documentBuilder->buildContext($jsonData);
        $renderedHtml = $this->documentBuilder->renderTemplate($template, $context);
        $htmlValidation = $this->htmlValidator->validate($renderedHtml);

        if (!$htmlValidation['valid']) {
            throw new \RuntimeException('HTML_VALIDATION:'.implode(' | ', $htmlValidation['errors']));
        }

        $pdfBinary = $this->documentBuilder->buildFromHtml($renderedHtml, [
            'paper_size' => $paperSize,
            'orientation' => $orientation,
        ]);

        return [
            'html' => $renderedHtml,
            'pdf' => $pdfBinary,
        ];
    }

    /**
     * @param array<string, mixed> $requestPayload
     */
    private function extractStoredJsonData(array $requestPayload): array
    {
        if (isset($requestPayload['json']) && is_array($requestPayload['json'])) {
            return $requestPayload['json'];
        }

        return $requestPayload;
    }

    /**
     * @param array<string, mixed> $requestPayload
     */
    private function extractStoredPaperSize(array $requestPayload): string
    {
        $paperSize = $requestPayload['paper_size'] ?? 'A4';

        return is_string($paperSize) && trim($paperSize) !== '' ? $paperSize : 'A4';
    }

    /**
     * @param array<string, mixed> $requestPayload
     */
    private function extractStoredOrientation(array $requestPayload): string
    {
        $orientation = $requestPayload['orientation'] ?? 'portrait';

        return is_string($orientation) && trim($orientation) !== '' ? $orientation : 'portrait';
    }
}
