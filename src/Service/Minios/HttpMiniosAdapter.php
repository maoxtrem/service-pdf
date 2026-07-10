<?php

namespace App\Service\Minios;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

final class HttpMiniosAdapter implements MiniosAdapterInterface
{
    private S3Client $internalClient;
    private S3Client $publicClient;

    public function __construct(
        private readonly string $minioEndpoint,
        private readonly string $minioPublicEndpoint,
        private readonly string $minioRegion,
        private readonly string $minioBucket,
        private readonly string $minioAccessKey,
        private readonly string $minioSecretKey,
        private readonly bool $minioUsePathStyle,
        private readonly bool $minioVerifySsl,
    ) {
        $this->internalClient = $this->createClient($this->minioEndpoint);
        $this->publicClient = $this->createClient($this->minioPublicEndpoint);
    }

    public function putObject(string $bucket, string $objectKey, string $content, string $contentType = 'application/pdf'): array
    {
        $bucketName = $bucket !== '' ? $bucket : $this->minioBucket;

        try {
            $result = $this->internalClient->putObject([
                'Bucket' => $bucketName,
                'Key' => $objectKey,
                'Body' => $content,
                'ContentType' => $contentType,
            ]);

            return [
                'ok' => true,
                'status_code' => 200,
                'body' => [
                    'etag' => (string) ($result['ETag'] ?? ''),
                    'bucket' => $bucketName,
                    'key' => $objectKey,
                ],
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible guardar el archivo en MinIO/S3.',
                    'details' => $exception->getAwsErrorMessage() ?: $exception->getMessage(),
                ],
            ];
        }
    }

    public function temporaryObjectUrl(string $bucket, string $objectKey, int $expiresInHours): string
    {
        $bucketName = $bucket !== '' ? $bucket : $this->minioBucket;
        $expiresInSeconds = max(1, $expiresInHours) * 3600;

        $command = $this->publicClient->getCommand('GetObject', [
            'Bucket' => $bucketName,
            'Key' => $objectKey,
        ]);

        $request = $this->publicClient->createPresignedRequest($command, sprintf('+%d seconds', $expiresInSeconds));

        return (string) $request->getUri();
    }

    public function downloadObject(string $bucket, string $objectKey): array
    {
        $bucketName = $bucket !== '' ? $bucket : $this->minioBucket;

        try {
            $result = $this->publicClient->getObject([
                'Bucket' => $bucketName,
                'Key' => $objectKey,
            ]);

            $body = isset($result['Body']) ? (string) $result['Body'] : '';

            return [
                'ok' => true,
                'status_code' => 200,
                'headers' => [
                    'content-type' => [(string) ($result['ContentType'] ?? 'application/pdf')],
                ],
                'body' => $body,
                'content_type' => (string) ($result['ContentType'] ?? 'application/pdf'),
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible descargar el archivo desde MinIO/S3.',
                    'details' => $exception->getAwsErrorMessage() ?: $exception->getMessage(),
                ],
            ];
        }
    }

    private function createClient(string $endpoint): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => $this->minioRegion,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => $this->minioUsePathStyle,
            'credentials' => new Credentials($this->minioAccessKey, $this->minioSecretKey),
            'http' => [
                'verify' => $this->minioVerifySsl,
            ],
        ]);
    }
}
