<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega un UUID interno a pdf_documents para identificar cada registro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_documents ADD uuid VARCHAR(36) DEFAULT NULL AFTER reference');
        $this->addSql('UPDATE pdf_documents SET uuid = UUID() WHERE uuid IS NULL OR uuid = \'\'' );
        $this->addSql('ALTER TABLE pdf_documents MODIFY uuid VARCHAR(36) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_pdf_documents_uuid ON pdf_documents (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pdf_documents_uuid ON pdf_documents');
        $this->addSql('ALTER TABLE pdf_documents DROP uuid');
    }
}
