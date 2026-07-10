<?php

namespace App\Service\Shared;

final class ResourceDocumentFinder
{
    public function __construct(
        private readonly ResourceIdentifierValidator $identifierValidator,
    ) {
    }

    /**
     * @template TDocument
     *
     * @param callable(string):(?TDocument) $findByUuid
     * @param callable(string):(?TDocument) $findByReference
     *
     * @return TDocument|null
     */
    public function find(string $identifier, callable $findByUuid, callable $findByReference): mixed
    {
        if ($this->identifierValidator->isValidUuid($identifier)) {
            return $findByUuid($identifier);
        }

        if ($this->identifierValidator->isValidReference($identifier)) {
            return $findByReference($identifier);
        }

        return null;
    }
}
