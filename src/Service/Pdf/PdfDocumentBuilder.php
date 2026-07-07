<?php

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfDocumentBuilder
{
    public function build(array $payload, string $reference): string
    {
        if (class_exists(Dompdf::class) && class_exists(Options::class)) {
            $options = new Options();
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isRemoteEnabled', false);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->wrapHtml($payload['html'] ?? '', $payload, $reference), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        }

        return $this->buildFallbackPdf($payload, $reference);
    }

    private function wrapHtml(string $html, array $payload, string $reference): string
    {
        $meta = sprintf(
            '<div style="font-family: DejaVu Sans; font-size: 10px; color: #666; margin-bottom: 12px;">Referencia: %s | Tenant: %s | Usuario: %s | Entorno: %s</div>',
            htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($payload['tenant'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($payload['usuario'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($payload['entorno'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'),
        );

        // Si ya es un documento HTML completo, intentamos inyectar el meta justo después de <body>
        if (preg_match('/<\s*body[^>]*>/i', $html, $matches)) {
            return str_replace($matches[0], $matches[0] . $meta, $html);
        }

        return sprintf(
            '<!doctype html><html><head><meta charset="UTF-8"></head><body>%s%s</body></html>',
            $meta,
            $html
        );
    }

    private function buildFallbackPdf(array $payload, string $reference): string
    {
        $htmlLength = strlen((string) ($payload['html'] ?? ''));
        $jsonString = $this->stringifyJson($payload['json'] ?? []);

        // Truncar el JSON para evitar que rompa excesivamente el renderizado crudo del PDF
        if (strlen($jsonString) > 100) {
            $jsonString = substr($jsonString, 0, 97) . '...';
        }

        $lines = [
            'Servicio PDF',
            'Referencia: ' . $reference,
            'Tenant: ' . ($payload['tenant'] ?? 'N/A'),
            'Usuario: ' . ($payload['usuario'] ?? 'N/A'),
            'Entorno: ' . ($payload['entorno'] ?? 'N/A'),
            'HTML length: ' . $htmlLength,
            'JSON preview: ' . $jsonString,
        ];

        $content = $this->buildContentStream($lines);

        $objects = [];
        $objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
        $objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj';
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

    private function stringifyJson(mixed $json): string
    {
        if (!is_array($json)) {
            return '{}';
        }

        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '{}';
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
}
