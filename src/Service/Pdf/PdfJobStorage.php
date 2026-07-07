<?php

namespace App\Service\Pdf;

final class PdfJobStorage
{
    public function store(string $reference, array $job): void
    {
        $directory = $this->jobDirectory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents(
            $this->jobPath($reference),
            json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    public function load(string $reference): ?array
    {
        $path = $this->jobPath($reference);

        if (!is_file($path)) {
            return null;
        }

        $job = json_decode((string) file_get_contents($path), true);

        return is_array($job) ? $job : null;
    }

    private function jobPath(string $reference): string
    {
        return $this->jobDirectory().'/'.$reference.'.json';
    }

    private function jobDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/service-pdf/pdf_jobs';
    }
}
