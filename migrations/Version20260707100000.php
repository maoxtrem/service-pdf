<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guarda el HTML original junto al JSON para permitir restaurar PDFs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_documents ADD html_content LONGTEXT NOT NULL AFTER entorno');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pdf_documents DROP html_content');
    }
}
