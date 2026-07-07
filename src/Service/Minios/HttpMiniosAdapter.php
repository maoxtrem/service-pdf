<?php

namespace App\Service\Minios;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class HttpMiniosAdapter implements MiniosAdapterInterface
{
    public function __construct(
        private readonly string $minioEndpoint,
        private readonly string $minioPublicEndpoint,
        private readonly string $minioRegion,
        private readonly string $minioBucket,
        private readonly string $minioAccessKey,
        private readonly string $minioSecretKey,
        private readonly bool $minioUsePathStyle,
        private readonly bool $minioVerifySsl,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function putObject(string $bucket, string $objectKey, string $content, string $contentType = 'application/pdf'): array
    {
        $bucketName = $bucket !== '' ? $bucket : $this->minioBucket;

        $url = $this->buildObjectUrl($this->minioEndpoint, $bucketName, $objectKey);

        return $this->sendSignedRequest('PUT', $url, $content, $contentType);
    }

    public function temporaryObjectUrl(string $bucket, string $objectKey, int $expiresInHours): string
    {
        $bucketName = $bucket !== '' ? $bucket : $this->minioBucket;
        $expiresInSeconds = max(1, $expiresInHours) * 3600;

        return $this->buildPresignedGetUrl($this->minioPublicEndpoint, $bucketName, $objectKey, $expiresInSeconds);
    }

    private function buildObjectUrl(string $baseUrl, string $bucket, string $objectKey): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = rtrim($parsed['path'] ?? '', '/');

        $encodedKey = implode('/', array_map('rawurlencode', array_filter(explode('/', $objectKey), 'strlen')));

        if ($this->minioUsePathStyle) {
            return sprintf('%s://%s%s%s/%s/%s', $scheme, $host, $port, $path, rawurlencode($bucket), $encodedKey);
        }

        // Virtual-hosted style
        return sprintf('%s://%s.%s%s%s/%s', $scheme, rawurlencode($bucket), $host, $port, $path, $encodedKey);
    }

    private function buildPresignedGetUrl(string $baseUrl, string $bucket, string $objectKey, int $expiresInSeconds): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $basePath = rtrim($parsed['path'] ?? '', '/');

        $encodedKey = implode('/', array_map('rawurlencode', array_filter(explode('/', $objectKey), 'strlen')));

        if ($this->minioUsePathStyle) {
            $canonicalUri = $basePath . '/' . rawurlencode($bucket) . ($encodedKey !== '' ? '/' . $encodedKey : '');
            $requestHost = $host . $port;
        } else {
            $canonicalUri = $basePath . '/' . $encodedKey;
            $requestHost = rawurlencode($bucket) . '.' . $host . $port;
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $dateScope = gmdate('Ymd');
        $credentialScope = $dateScope . '/' . $this->minioRegion . '/s3/aws4_request';
        $signedHeaders = 'host';

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->minioAccessKey . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];

        $canonicalQueryString = $this->buildCanonicalQueryString($queryParams);
        $canonicalHeaders = 'host:' . $requestHost . "\n";

        $canonicalRequest = implode("\n", [
            'GET',
            $this->encodePath($canonicalUri),
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signatureKey = $this->getSignatureKey($this->minioSecretKey, $dateScope, $this->minioRegion, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signatureKey);

        $queryParams['X-Amz-Signature'] = $signature;

        return sprintf('%s://%s%s?%s', $scheme, $requestHost, $canonicalUri, http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986));
    }

    private function sendSignedRequest(string $method, string $url, string $content, string $contentType, bool $includePayload = true): array
    {
        $payloadHash = hash('sha256', $content);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateScope = gmdate('Ymd');

        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? '';
        if (isset($parsedUrl['port'])) {
            $host .= ':' . $parsedUrl['port'];
        }
        $path = $parsedUrl['path'] ?? '/';

        $headers = [
            'content-type' => $contentType,
            'host' => $host,
            'x-amz-content-sha256' => $includePayload ? $payloadHash : hash('sha256', ''),
            'x-amz-date' => $amzDate,
        ];

        $authorization = $this->buildAuthorizationHeader(
            $method,
            $path,
            '',
            $headers,
            $includePayload ? $payloadHash : hash('sha256', ''),
            $amzDate,
            $dateScope
        );

        $headers['Authorization'] = $authorization;
        try {
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $includePayload ? $content : null,
                'verify_peer' => $this->minioVerifySsl,
                'verify_host' => $this->minioVerifySsl,
            ]);

            $statusCode = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $responseBody = $response->getContent(false);

            return $this->normalizeResponse($statusCode, $responseHeaders, $responseBody);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'status_code' => 502,
                'body' => [
                    'error' => 'No fue posible conectar con MinIO/S3.',
                    'details' => $e->getMessage(),
                ],
            ];
        }
    }

    private function buildAuthorizationHeader(
        string $method,
        string $canonicalUri,
        string $canonicalQueryString,
        array $headers,
        string $payloadHash,
        string $amzDate,
        string $dateScope,
    ): string {
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = [];

        foreach ($headers as $name => $value) {
            $normalizedName = strtolower(trim((string) $name));
            $normalizedValue = trim((string) $value);
            $canonicalHeaders .= $normalizedName . ':' . $normalizedValue . "\n";
            $signedHeaders[] = $normalizedName;
        }

        $signedHeadersString = implode(';', $signedHeaders);
        $canonicalRequest = implode("\n", [
            $method,
            $this->encodePath($canonicalUri),
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeadersString,
            $payloadHash,
        ]);

        $credentialScope = $dateScope . '/' . $this->minioRegion . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->getSignatureKey($this->minioSecretKey, $dateScope, $this->minioRegion, 's3');
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->minioAccessKey,
            $credentialScope,
            $signedHeadersString,
            $signature
        );
    }

    private function encodePath(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private function getSignatureKey(string $key, string $dateStamp, string $regionName, string $serviceName): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function normalizeResponse(int $statusCode, array $responseHeaders, string $responseBody): array
    {
        $body = [];

        if ($responseBody !== '') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($responseBody);

            if ($xml !== false) {
                // S3 y Minio envían la estructura de error como XML
                $body = json_decode((string) json_encode($xml), true);
            } else {
                $body = [
                    'raw_preview' => substr($responseBody, 0, 500),
                    'raw_length' => strlen($responseBody),
                ];
            }
            libxml_clear_errors();
        }

        return [
            'ok' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    private function buildCanonicalQueryString(array $queryParams): string
    {
        ksort($queryParams);
        $parts = [];

        foreach ($queryParams as $key => $value) {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
