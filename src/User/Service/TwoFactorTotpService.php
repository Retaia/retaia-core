<?php

namespace App\User\Service;

use OTPHP\TOTP;

final class TwoFactorTotpService
{
    public const ISSUER = 'Retaia';

    public function __construct(
        private TwoFactorSecretCipher $secretCipher,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @return array{method: string, issuer: string, account_name: string, secret: string, otpauth_uri: string}
     */
    public function beginSetup(array &$state, string $email): array
    {
        if ((bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_ALREADY_ENABLED');
        }

        $totp = TOTP::generate();
        $totp->setLabel($email);
        $totp->setIssuer(self::ISSUER);
        $totp->setIssuerIncludedAsParameter(true);

        $secret = $totp->getSecret();
        $state['pending_secret_encrypted'] = $this->secretCipher->encrypt($secret);
        unset($state['pending_secret']);

        return [
            'method' => 'TOTP',
            'issuer' => self::ISSUER,
            'account_name' => $email,
            'secret' => $secret,
            'otpauth_uri' => $totp->getProvisioningUri(),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function enable(array &$state, string $otpCode): bool
    {
        if ((bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_ALREADY_ENABLED');
        }

        $pendingSecret = $this->resolveSecretFromState($state, 'pending_secret_encrypted', 'pending_secret');
        if ($pendingSecret === '') {
            throw new \RuntimeException('MFA_SETUP_REQUIRED');
        }
        if (!$this->isValidOtp($pendingSecret, $otpCode)) {
            return false;
        }

        $state['enabled'] = true;
        $state['secret_encrypted'] = $this->secretCipher->encrypt($pendingSecret);
        unset($state['secret']);
        unset($state['pending_secret_encrypted']);
        unset($state['pending_secret']);

        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function disable(array &$state, string $otpCode): bool
    {
        if (!(bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_NOT_ENABLED');
        }

        $secret = $this->resolveSecretFromState($state, 'secret_encrypted', 'secret');

        if ($secret === '' || !$this->isValidOtp($secret, $otpCode)) {
            return false;
        }

        $state = ['enabled' => false];

        return true;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function verifyLoginOtp(array &$state, string $otpCode): bool
    {
        if (!(bool) ($state['enabled'] ?? false)) {
            return true;
        }

        $secret = $this->resolveSecretFromState($state, 'secret_encrypted', 'secret');
        if ($secret === '') {
            return false;
        }

        return $this->isValidOtp($secret, $otpCode);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function verifyEnabledOtp(array &$state, string $otpCode): bool
    {
        if (!(bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_NOT_ENABLED');
        }

        $secret = $this->resolveSecretFromState($state, 'secret_encrypted', 'secret');
        if ($secret === '') {
            return false;
        }

        return $this->isValidOtp($secret, $otpCode);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function hasPendingSetup(array $state): bool
    {
        return is_string($state['pending_secret_encrypted'] ?? null) && $state['pending_secret_encrypted'] !== ''
            || (is_string($state['pending_secret'] ?? null) && $state['pending_secret'] !== '');
    }

    private function isValidOtp(string $secret, string $otpCode): bool
    {
        $code = trim($otpCode);
        if ($code === '') {
            return false;
        }

        $totp = TOTP::createFromSecret($secret);

        return $totp->verify($code, null, 29);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function resolveSecretFromState(array &$state, string $encryptedKey, string $legacyKey): string
    {
        $encrypted = $state[$encryptedKey] ?? null;
        if (is_string($encrypted) && $encrypted !== '') {
            $secret = $this->secretCipher->decrypt($encrypted);
            if (!is_string($secret) || $secret === '') {
                return '';
            }
            if ($this->secretCipher->needsRotation($encrypted)) {
                $state[$encryptedKey] = $this->secretCipher->encrypt($secret);
            }

            return $secret;
        }

        $legacy = $state[$legacyKey] ?? null;
        if (!is_string($legacy) || $legacy === '') {
            return '';
        }

        $state[$encryptedKey] = $this->secretCipher->encrypt($legacy);
        unset($state[$legacyKey]);

        return $legacy;
    }
}
