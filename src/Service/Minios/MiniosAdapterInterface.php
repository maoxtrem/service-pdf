<?php

namespace App\Service\Minios;

interface MiniosAdapterInterface
{
    public function putObject(string $bucket, string $objectKey, string $content, string $contentType = 'application/pdf'): array;

    public function temporaryObjectUrl(string $bucket, string $objectKey, int $expiresInHours): string;

    public function downloadObject(string $bucket, string $objectKey): array;
}
