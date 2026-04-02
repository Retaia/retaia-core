<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260403113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add database-level invariants for jobs, auth and derived persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_status CHECK (status IN ('pending', 'claimed', 'completed', 'failed'))");
        $this->addSql('ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_fencing_positive CHECK (fencing_token IS NULL OR fencing_token > 0)');
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_claimed_fields CHECK (
            status <> 'claimed'
            OR (
                claimed_by IS NOT NULL
                AND claimed_at IS NOT NULL
                AND lock_token IS NOT NULL
                AND fencing_token IS NOT NULL
                AND locked_until IS NOT NULL
                AND completed_by IS NULL
                AND completed_at IS NULL
                AND failed_by IS NULL
                AND failed_at IS NULL
            )
        )");
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_non_claimed_lock_fields CHECK (
            status = 'claimed'
            OR (
                lock_token IS NULL
                AND fencing_token IS NULL
                AND locked_until IS NULL
            )
        )");
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_claimed_by_coherence CHECK (
            (status IN ('claimed', 'failed') AND claimed_by IS NOT NULL)
            OR (status IN ('pending', 'completed') AND claimed_by IS NULL)
        )");
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_completed_fields CHECK (
            (status = 'completed' AND completed_by IS NOT NULL AND completed_at IS NOT NULL AND failed_by IS NULL AND failed_at IS NULL)
            OR (status <> 'completed' AND completed_by IS NULL AND completed_at IS NULL)
        )");
        $this->addSql("ALTER TABLE processing_job ADD CONSTRAINT chk_processing_job_failed_fields CHECK (
            (status = 'failed' AND failed_by IS NOT NULL AND failed_at IS NOT NULL AND completed_by IS NULL AND completed_at IS NULL)
            OR (status <> 'failed' AND failed_by IS NULL AND failed_at IS NULL)
        )");

        $this->addSql("ALTER TABLE user_auth_session ADD CONSTRAINT chk_user_auth_session_not_blank CHECK (
            btrim(session_id) <> ''
            AND strpos(session_id, '|') = 0
            AND btrim(access_token) <> ''
            AND btrim(refresh_token) <> ''
            AND btrim(user_id) <> ''
            AND btrim(email) <> ''
            AND btrim(client_id) <> ''
            AND btrim(client_kind) <> ''
        )");
        $this->addSql('ALTER TABLE user_auth_session ADD CONSTRAINT chk_user_auth_session_access_expiry CHECK (access_expires_at > 0)');
        $this->addSql('ALTER TABLE user_auth_session ADD CONSTRAINT chk_user_auth_session_refresh_window CHECK (refresh_expires_at >= access_expires_at)');
        $this->addSql('ALTER TABLE user_auth_session ADD CONSTRAINT chk_user_auth_session_last_used CHECK (last_used_at >= created_at)');

        $this->addSql("ALTER TABLE user_two_factor_state ADD CONSTRAINT chk_user_two_factor_state_user_id CHECK (btrim(user_id) <> '')");
        $this->addSql('ALTER TABLE user_two_factor_state ADD CONSTRAINT chk_user_two_factor_state_timestamps CHECK (created_at >= 0 AND updated_at >= created_at)');
        $this->addSql("ALTER TABLE user_two_factor_state ADD CONSTRAINT chk_user_two_factor_state_enabled_secret CHECK (
            NOT enabled
            OR (secret_encrypted IS NOT NULL AND btrim(secret_encrypted) <> '')
        )");

        $this->addSql("ALTER TABLE derived_upload_session ADD CONSTRAINT chk_derived_upload_session_status CHECK (status IN ('open', 'completed'))");
        $this->addSql('ALTER TABLE derived_upload_session ADD CONSTRAINT chk_derived_upload_session_size CHECK (size_bytes >= 0)');
        $this->addSql('ALTER TABLE derived_upload_session ADD CONSTRAINT chk_derived_upload_session_parts CHECK (parts_count >= 0)');
        $this->addSql('ALTER TABLE derived_upload_session ADD CONSTRAINT chk_derived_upload_session_timestamps CHECK (updated_at >= created_at)');

        $this->addSql("ALTER TABLE asset_derived_file ADD CONSTRAINT chk_asset_derived_file_storage_path CHECK (btrim(storage_path) <> '')");
        $this->addSql('ALTER TABLE asset_derived_file ADD CONSTRAINT chk_asset_derived_file_size CHECK (size_bytes >= 0)');

        $this->addSql("ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_not_blank CHECK (
            btrim(device_code) <> ''
            AND btrim(user_code) <> ''
            AND btrim(client_kind) <> ''
        )");
        $this->addSql("ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_status CHECK (status IN ('PENDING', 'APPROVED', 'DENIED', 'EXPIRED'))");
        $this->addSql('ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_expiry CHECK (expires_at >= created_at)');
        $this->addSql('ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_interval CHECK (interval_seconds > 0 AND last_polled_at >= 0)');
        $this->addSql("ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_approved_pair CHECK (
            (approved_client_id IS NULL AND approved_secret_key IS NULL)
            OR (approved_client_id IS NOT NULL AND approved_secret_key IS NOT NULL)
        )");
        $this->addSql("ALTER TABLE auth_device_flow ADD CONSTRAINT chk_auth_device_flow_approved_status CHECK (
            status <> 'APPROVED'
            OR (approved_client_id IS NOT NULL AND approved_secret_key IS NOT NULL)
        )");

        $this->addSql("ALTER TABLE auth_mcp_challenge ADD CONSTRAINT chk_auth_mcp_challenge_not_blank CHECK (
            btrim(challenge_id) <> ''
            AND btrim(client_id) <> ''
            AND btrim(openpgp_fingerprint) <> ''
            AND btrim(challenge) <> ''
        )");
        $this->addSql('ALTER TABLE auth_mcp_challenge ADD CONSTRAINT chk_auth_mcp_challenge_expires CHECK (expires_at > 0)');
        $this->addSql('ALTER TABLE auth_mcp_challenge ADD CONSTRAINT chk_auth_mcp_challenge_used_at CHECK ((used = FALSE AND used_at IS NULL) OR (used = TRUE AND used_at IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE auth_mcp_challenge DROP CONSTRAINT chk_auth_mcp_challenge_used_at');
        $this->addSql('ALTER TABLE auth_mcp_challenge DROP CONSTRAINT chk_auth_mcp_challenge_expires');
        $this->addSql('ALTER TABLE auth_mcp_challenge DROP CONSTRAINT chk_auth_mcp_challenge_not_blank');

        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_approved_status');
        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_approved_pair');
        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_interval');
        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_expiry');
        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_status');
        $this->addSql('ALTER TABLE auth_device_flow DROP CONSTRAINT chk_auth_device_flow_not_blank');

        $this->addSql('ALTER TABLE asset_derived_file DROP CONSTRAINT chk_asset_derived_file_size');
        $this->addSql('ALTER TABLE asset_derived_file DROP CONSTRAINT chk_asset_derived_file_storage_path');

        $this->addSql('ALTER TABLE derived_upload_session DROP CONSTRAINT chk_derived_upload_session_timestamps');
        $this->addSql('ALTER TABLE derived_upload_session DROP CONSTRAINT chk_derived_upload_session_parts');
        $this->addSql('ALTER TABLE derived_upload_session DROP CONSTRAINT chk_derived_upload_session_size');
        $this->addSql('ALTER TABLE derived_upload_session DROP CONSTRAINT chk_derived_upload_session_status');

        $this->addSql('ALTER TABLE user_two_factor_state DROP CONSTRAINT chk_user_two_factor_state_enabled_secret');
        $this->addSql('ALTER TABLE user_two_factor_state DROP CONSTRAINT chk_user_two_factor_state_timestamps');
        $this->addSql('ALTER TABLE user_two_factor_state DROP CONSTRAINT chk_user_two_factor_state_user_id');

        $this->addSql('ALTER TABLE user_auth_session DROP CONSTRAINT chk_user_auth_session_last_used');
        $this->addSql('ALTER TABLE user_auth_session DROP CONSTRAINT chk_user_auth_session_refresh_window');
        $this->addSql('ALTER TABLE user_auth_session DROP CONSTRAINT chk_user_auth_session_access_expiry');
        $this->addSql('ALTER TABLE user_auth_session DROP CONSTRAINT chk_user_auth_session_not_blank');

        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_failed_fields');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_completed_fields');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_claimed_by_coherence');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_non_claimed_lock_fields');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_claimed_fields');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_fencing_positive');
        $this->addSql('ALTER TABLE processing_job DROP CONSTRAINT chk_processing_job_status');
    }
}
