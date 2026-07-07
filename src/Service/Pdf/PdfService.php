<?php

namespace App\Service\Pdf;

use App\Service\Minios\MiniosAdapterInterface;

final class PdfService
{
    public function __construct(
        private readonly PdfHtmlValidator $htmlValidator,
        private readonly PdfReferenceGenerator $referenceGenerator,
        private readonly PdfDocumentBuilder $documentBuilder,
        private readonly PdfJobStorage $jobStorage,
        private readonly MiniosAdapterInterface $miniosAdapter,
        private readonly string $minioBucket,
        private readonly int $minioUrlExpirationHours,
    ) {
    }

    public function generate(array $payload): array
    {
        $requiredFields = ['tenant', 'usuario', 'entorno', 'html', 'json'];
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

        $htmlValidation = $this->htmlValidator->validate($payload['html']);

        if (!$htmlValidation['valid']) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'El campo "html" no contiene HTML válido.',
                    'details' => $htmlValidation['errors'],
                ],
            ];
        }

        if (!is_array($payload['json'])) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'El campo "json" debe ser un objeto JSON.',
                ],
            ];
        }

        $referenceData = $this->referenceGenerator->generate();
        $reference = $referenceData['value'];
        $pdfBinary = $this->documentBuilder->build($payload, $reference);
        $bucket = $this->minioBucket;
        $objectKey = $reference;

        $uploadResult = $this->miniosAdapter->putObject($bucket, $objectKey, $pdfBinary, 'application/pdf');
        $pdfUrl = $this->miniosAdapter->temporaryObjectUrl($bucket, $objectKey, $this->minioUrlExpirationHours);

        $miniosContext = [
            'attempted' => true,
            'ok' => $uploadResult['ok'] ?? false,
            'status_code' => $uploadResult['status_code'] ?? 0,
            'body' => $uploadResult['body'] ?? [],
        ];

        $job = [
            'reference' => $reference,
            'reference_hex' => $referenceData['hex'],
            'reference_uuid' => $referenceData['uuid'],
            'tenant' => $payload['tenant'],
            'usuario' => $payload['usuario'],
            'entorno' => $payload['entorno'],
            'html' => $payload['html'],
            'json' => $payload['json'],
            'object_key' => $objectKey,
            'bucket' => $bucket,
            'pdf_url' => $pdfUrl,
            'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
            'minios' => $miniosContext,
            'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'status' => $miniosContext['ok'] ? 'stored' : 'failed',
        ];

        $this->jobStorage->store($reference, $job);

        if (!$miniosContext['ok']) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible guardar el PDF en MinIO.',
                    'reference' => $reference,
                    'reference_hex' => $referenceData['hex'],
                    'reference_uuid' => $referenceData['uuid'],
                    'pdf_url' => $pdfUrl,
                    'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
                    'object_key' => $objectKey,
                    'bucket' => $bucket,
                    'minios' => $miniosContext,
                    'data' => [
                        'tenant' => $payload['tenant'],
                        'usuario' => $payload['usuario'],
                        'entorno' => $payload['entorno'],
                        'html_length' => strlen($payload['html']),
                        'json_keys' => array_keys($payload['json']),
                    ],
                ],
            ];
        }

        return [
            'ok' => true,
            'status_code' => 201,
            'body' => [
                'status' => 'stored',
                'message' => 'Payload recibido correctamente. El PDF fue generado y guardado en MinIO.',
                'reference' => $reference,
                'reference_hex' => $referenceData['hex'],
                'reference_uuid' => $referenceData['uuid'],
                'pdf_url' => $pdfUrl,
                'pdf_url_expires_in_hours' => $this->minioUrlExpirationHours,
                'object_key' => $objectKey,
                'bucket' => $bucket,
                'minios' => $miniosContext,
                'data' => [
                    'tenant' => $payload['tenant'],
                    'usuario' => $payload['usuario'],
                    'entorno' => $payload['entorno'],
                    'html_length' => strlen($payload['html']),
                    'json_keys' => array_keys($payload['json']),
                ],
            ],
        ];
    }

    public function resolve(string $reference): array
    {
        if (!$this->isValidReference($reference)) {
            return [
                'ok' => false,
                'status_code' => 400,
                'body' => [
                    'error' => 'Referencia inválida.',
                ],
            ];
        }

        $job = $this->jobStorage->load($reference);

        if ($job === null) {
            return [
                'ok' => false,
                'status_code' => 404,
                'body' => [
                    'error' => 'No se encontró un PDF asociado a esta referencia.',
                ],
            ];
        }

        return [
            'ok' => true,
            'status_code' => 200,
            'body' => [
                'status' => $job['status'] ?? 'stored',
                'reference' => $reference,
                'message' => 'La solicitud ya cuenta con un PDF almacenado en MinIO.',
                'generated_at' => $job['created_at'] ?? null,
                'entorno' => $job['entorno'] ?? null,
                'pdf_url' => $job['pdf_url'] ?? null,
                'object_key' => $job['object_key'] ?? null,
                'bucket' => $job['bucket'] ?? null,
            ],
        ];
    }

    private function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{32}-[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $reference) === 1;
    }

}
