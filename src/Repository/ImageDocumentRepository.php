<?php

namespace App\Repository;

use App\Entity\ImageDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImageDocument>
 */
final class ImageDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImageDocument::class);
    }

    public function findByReference(string $reference): ?ImageDocument
    {
        /** @var ImageDocument|null $document */
        $document = $this->findOneBy(['reference' => $reference]);

        return $document;
    }

    public function findByUuid(string $uuid): ?ImageDocument
    {
        /** @var ImageDocument|null $document */
        $document = $this->findOneBy(['uuid' => $uuid]);

        return $document;
    }

    /**
     * @return list<ImageDocument>
     */
    public function findByFilters(string $usuario, string $entorno, ?string $tenant = null, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('img')
            ->andWhere('img.usuario = :usuario')
            ->andWhere('img.entorno = :entorno')
            ->andWhere('img.status <> :deletedStatus')
            ->setParameter('usuario', $usuario)
            ->setParameter('entorno', $entorno)
            ->setParameter('deletedStatus', 'deleted')
            ->orderBy('img.createdAt', 'DESC');

        if ($tenant !== null && $tenant !== '') {
            $qb->andWhere('img.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var list<ImageDocument> $documents */
        $documents = $qb->getQuery()->getResult();

        return $documents;
    }
}
