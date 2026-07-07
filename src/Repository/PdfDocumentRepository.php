<?php

namespace App\Repository;

use App\Entity\PdfDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PdfDocument>
 */
final class PdfDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PdfDocument::class);
    }

    public function findByReference(string $reference): ?PdfDocument
    {
        /** @var PdfDocument|null $document */
        $document = $this->findOneBy(['reference' => $reference]);

        return $document;
    }

    public function findByUuid(string $uuid): ?PdfDocument
    {
        /** @var PdfDocument|null $document */
        $document = $this->findOneBy(['uuid' => $uuid]);

        return $document;
    }

    /**
     * @return list<PdfDocument>
     */
    public function findByFilters(string $usuario, string $entorno, ?string $tenant = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('pdf')
            ->andWhere('pdf.usuario = :usuario')
            ->andWhere('pdf.entorno = :entorno')
            ->setParameter('usuario', $usuario)
            ->setParameter('entorno', $entorno)
            ->orderBy('pdf.createdAt', 'DESC');

        if ($tenant !== null && $tenant !== '') {
            $qb->andWhere('pdf.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<PdfDocument> $documents */
        $documents = $qb->getQuery()->getResult();

        return $documents;
    }

    /**
     * @return list<PdfDocument>
     */
    public function findByTenantAndEntorno(string $tenant, string $entorno, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('pdf')
            ->andWhere('pdf.tenant = :tenant')
            ->andWhere('pdf.entorno = :entorno')
            ->setParameter('tenant', $tenant)
            ->setParameter('entorno', $entorno)
            ->orderBy('pdf.createdAt', 'DESC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<PdfDocument> $documents */
        $documents = $qb->getQuery()->getResult();

        return $documents;
    }
}
