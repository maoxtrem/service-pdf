<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706232200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla pdf_documents para registrar PDFs generados y sus keys de MinIO.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pdf_documents (
            id INT AUTO_INCREMENT NOT NULL,
            reference VARCHAR(69) NOT NULL,
            tenant VARCHAR(180) NOT NULL,
            usuario VARCHAR(180) NOT NULL,
            entorno VARCHAR(100) NOT NULL,
            request_payload JSON NOT NULL,
            object_key VARCHAR(255) NOT NULL,
            bucket VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            processed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_pdf_documents_reference (reference),
            UNIQUE INDEX uniq_pdf_documents_object_key (object_key),
            INDEX idx_pdf_documents_lookup (tenant, usuario, entorno),
            INDEX idx_pdf_documents_usuario_entorno (usuario, entorno),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pdf_documents');
    }
}
