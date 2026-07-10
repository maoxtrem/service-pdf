<?php

namespace App\Service\Image;

final class ImageObjectKeyGenerator
{
    public function generate(string $extension = 'bin'): string
    {
        $normalizedExtension = strtolower(trim($extension));
        $normalizedExtension = preg_replace('/[^a-z0-9]+/', '', $normalizedExtension) ?: 'bin';

        return bin2hex(random_bytes(32)).'.'.$normalizedExtension;
    }
}
