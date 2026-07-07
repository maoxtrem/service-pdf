<?php

namespace App\Entity;

use App\Repository\PdfDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PdfDocumentRepository::class)]
#[ORM\Table(name: 'pdf_documents')]
#[ORM\Index(name: 'idx_pdf_documents_lookup', columns: ['tenant', 'usuario', 'entorno'])]
#[ORM\Index(name: 'idx_pdf_documents_usuario_entorno', columns: ['usuario', 'entorno'])]
#[ORM\HasLifecycleCallbacks]
class PdfDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 69, unique: true)]
    private string $reference;

    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\Column(length: 180)]
    private string $tenant;

    #[ORM\Column(length: 180)]
    private string $usuario;

    #[ORM\Column(length: 100)]
    private string $entorno;

    #[ORM\Column(name: 'request_payload', type: 'json')]
    private array $requestPayload = [];

    #[ORM\Column(name: 'object_key', length: 255, unique: true)]
    private string $objectKey;

    #[ORM\Column(length: 100)]
    private string $bucket;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(
        string $reference,
        string $uuid,
        string $tenant,
        string $usuario,
        string $entorno,
        array $requestPayload,
        string $objectKey,
        string $bucket,
    ) {
        $now = new \DateTimeImmutable();

        $this->reference = $reference;
        $this->uuid = $uuid;
        $this->tenant = $tenant;
        $this->usuario = $usuario;
        $this->entorno = $entorno;
        $this->requestPayload = $requestPayload;
        $this->objectKey = $objectKey;
        $this->bucket = $bucket;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markProcessed(): void
    {
        $now = new \DateTimeImmutable();

        $this->status = 'stored';
        $this->processedAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function getUsuario(): string
    {
        return $this->usuario;
    }

    public function getEntorno(): string
    {
        return $this->entorno;
    }

    public function getRequestPayload(): array
    {
        return $this->requestPayload;
    }

    public function getObjectKey(): string
    {
        return $this->objectKey;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }
}
