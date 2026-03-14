<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'webauthn_device')]
#[ORM\UniqueConstraint(name: 'uniq_webauthn_device_user_credential', columns: ['user_id', 'credential_id'])]
class WebAuthnDevice
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'credential_id', type: 'string', length: 191)]
        private string $credentialId,
        #[ORM\Column(name: 'device_label', type: 'string', length: 128)]
        private string $deviceLabel,
        #[ORM\Column(name: 'webauthn_fingerprint', type: 'string', length: 64)]
        private string $webauthnFingerprint,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }
}

