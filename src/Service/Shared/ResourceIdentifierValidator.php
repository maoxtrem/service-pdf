<?php

namespace App\Service\Shared;

final class ResourceIdentifierValidator
{
    public function isValidReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{32}-[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $reference) === 1;
    }

    public function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid) === 1;
    }
}
