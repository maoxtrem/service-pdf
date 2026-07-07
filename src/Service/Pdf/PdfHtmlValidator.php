<?php

namespace App\Service\Pdf;

final class PdfHtmlValidator
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_SEMANTIC_TAGS = [
        'article',
        'aside',
        'footer',
        'header',
        'main',
        'nav',
        'section',
        'figure',
        'figcaption',
    ];

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
            $message = trim($error->message);
            if ($this->isAllowedSemanticTagWarning($message)) {
                continue;
            }

            $errors[] = $message;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        return [
            'valid' => $loaded && $errors === [],
            'errors' => $errors,
        ];
    }

    private function isAllowedSemanticTagWarning(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        if (preg_match('/Tag\s+([a-z0-9:-]+)\s+invalid/i', $message, $matches) !== 1) {
            return false;
        }

        $tag = strtolower((string) ($matches[1] ?? ''));

        return in_array($tag, self::ALLOWED_SEMANTIC_TAGS, true);
    }
}
