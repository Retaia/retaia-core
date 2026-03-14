<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260314043000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist WebAuthn registered devices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webauthn_device (id VARCHAR(36) NOT NULL, user_id VARCHAR(32) NOT NULL, credential_id VARCHAR(191) NOT NULL, device_label VARCHAR(128) NOT NULL, webauthn_fingerprint VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_webauthn_device_user ON webauthn_device (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_webauthn_device_user_credential ON webauthn_device (user_id, credential_id)');
        $this->addSql('ALTER TABLE webauthn_device ADD CONSTRAINT fk_webauthn_device_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webauthn_device');
    }
}

