<?php

namespace App\Service\Pdf;

final class PdfObjectKeyGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32)).'.pdf';
    }
}
