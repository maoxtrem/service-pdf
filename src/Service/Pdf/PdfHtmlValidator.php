<?php

namespace App\Service\Pdf;

final class PdfHtmlValidator
{
    public function validate(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return [
                'valid' => false,
                'errors' => ['El HTML no puede estar vacío.'],
            ];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $isFullDocument = preg_match('/<\s*!doctype\b|<\s*html\b/i', $html) === 1;

        if ($isFullDocument) {
            $loaded = $dom->loadHTML($html);
        } else {
            $loaded = $dom->loadHTML(
                '<!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        }

        $errors = [];

        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return [
            'valid' => $loaded && $errors === [],
            'errors' => $errors,
        ];
    }
}
