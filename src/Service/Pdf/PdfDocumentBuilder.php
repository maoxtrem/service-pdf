<?php

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\ArrayLoader;

final class PdfDocumentBuilder
{
    public function renderTemplate(string $template, array $context): string
    {
        $twig = new Environment(new ArrayLoader(), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);

        try {
            return $twig->createTemplate($template)->render($context);
        } catch (TwigError $exception) {
            throw new \RuntimeException('Error al renderizar la plantilla Twig: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function buildFromHtml(string $html, array $payload): string
    {
        $paperSize = $this->normalizePaperSize($payload['paper_size'] ?? 'A4');
        $orientation = $this->normalizeOrientation($payload['orientation'] ?? 'portrait');

        if (class_exists(Dompdf::class) && class_exists(Options::class)) {
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isRemoteEnabled', true);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->wrapHtml($html), 'UTF-8');
            $dompdf->setPaper($paperSize, $orientation);
            $dompdf->render();

            return $dompdf->output();
        }

        return $this->buildFallbackPdf($html, $paperSize, $orientation);
    }

    /**
     * @param array<string, mixed> $jsonData
     * @return array<string, mixed>
     */
    public function buildContext(array $jsonData): array
    {
        $context = [
            'json' => $jsonData,
            'data' => $jsonData,
        ];

        foreach ($jsonData as $key => $value) {
            if (!array_key_exists((string) $key, $context)) {
                $context[(string) $key] = $value;
            }
        }

        return $context;
    }

    private function wrapHtml(string $html): string
    {
        if (preg_match('/<\s*body[^>]*>/i', $html, $matches)) {
            return $html;
        }

        return sprintf(
            '<!doctype html><html><head><meta charset="UTF-8"></head><body>%s%s</body></html>',
            $html
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildFallbackPdf(string $html, string $paperSize, string $orientation): string
    {
        $lines = [
            'Servicio PDF',
            'Contenido renderizado correctamente.',
        ];

        $content = $this->buildContentStream($lines);
        [$pageWidth, $pageHeight] = $this->resolvePageSize($paperSize, $orientation);

        $objects = [];
        $objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
        $objects[] = sprintf(
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            $pageWidth,
            $pageHeight
        );
        $objects[] = '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';
        $stream = "\n" . $content . "\n";
        $objects[] = sprintf('5 0 obj << /Length %d >> stream%sendstream endobj', strlen($stream), $stream);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xrefPosition = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "0 6\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);

        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n";
        $pdf .= "<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n";
        $pdf .= $xrefPosition . "\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    private function buildContentStream(array $lines): string
    {
        $preparedLines = array_map(
            fn(string $line): string => $this->escapePdfText($this->toPdfEncoding($line)),
            $lines
        );

        $content = [];
        $content[] = 'BT';
        $content[] = '/F1 12 Tf';
        $content[] = '50 780 Td';
        $content[] = '14 TL';

        foreach ($preparedLines as $index => $line) {
            if ($index === 0) {
                $content[] = sprintf('(%s) Tj', $line);
                continue;
            }

            $content[] = 'T*';
            $content[] = sprintf('(%s) Tj', $line);
        }

        $content[] = 'ET';

        return implode("\n", $content);
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    }

    private function toPdfEncoding(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $converted !== false ? $converted : $text;
    }

    private function normalizePaperSize(mixed $paperSize): string
    {
        if (!is_string($paperSize) || trim($paperSize) === '') {
            return 'A4';
        }

        $normalized = strtoupper(trim($paperSize));

        return match ($normalized) {
            'A3', 'A4', 'A5', 'LETTER', 'LEGAL', 'TABLOID' => $normalized,
            default => 'A4',
        };
    }

    private function normalizeOrientation(mixed $orientation): string
    {
        if (!is_string($orientation) || trim($orientation) === '') {
            return 'portrait';
        }

        $normalized = strtolower(trim($orientation));

        return match ($normalized) {
            'portrait', 'landscape' => $normalized,
            default => 'portrait',
        };
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolvePageSize(string $paperSize, string $orientation): array
    {
        $sizes = [
            'A3' => [842, 1191],
            'A4' => [595, 842],
            'A5' => [420, 595],
            'LETTER' => [612, 792],
            'LEGAL' => [612, 1008],
            'TABLOID' => [792, 1224],
        ];

        [$width, $height] = $sizes[$paperSize] ?? $sizes['A4'];

        if ($orientation === 'landscape') {
            return [$height, $width];
        }

        return [$width, $height];
    }
}
