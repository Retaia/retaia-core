<?php

namespace App\User\Service;

use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;

final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private TwoFactorSecretCipher $secretCipher,
    ) {
    }

    public function isEnabled(string $userId): bool
    {
        $state = $this->state($userId);

        return (bool) ($state['enabled'] ?? false);
    }

    public function isPendingSetup(string $userId): bool
    {
        $state = $this->state($userId);

        return is_string($state['pending_secret_encrypted'] ?? null) && $state['pending_secret_encrypted'] !== ''
            || (is_string($state['pending_secret'] ?? null) && $state['pending_secret'] !== '');
    }

    /**
     * @return array{secret: string, otpauth_uri: string}
     */
    public function setup(string $userId, string $email): array
    {
        $state = $this->state($userId);
        if ((bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_ALREADY_ENABLED');
        }

        $totp = TOTP::generate();
        $totp->setLabel($email);
        $totp->setIssuer('Retaia');
        $totp->setIssuerIncludedAsParameter(true);

        $secret = $totp->getSecret();
        $state['pending_secret_encrypted'] = $this->secretCipher->encrypt($secret);
        unset($state['pending_secret']);
        $this->saveState($userId, $state);

        return [
            'secret' => $secret,
            'otpauth_uri' => $totp->getProvisioningUri(),
        ];
    }

    public function enable(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        $pendingSecret = $this->resolveSecretFromState($state, 'pending_secret_encrypted', 'pending_secret');
        if ($pendingSecret === '') {
            throw new \RuntimeException('MFA_SETUP_REQUIRED');
        }
        if ((bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_ALREADY_ENABLED');
        }
        if (!$this->isValidOtp($pendingSecret, $otpCode)) {
            return false;
        }

        $state['enabled'] = true;
        $state['secret_encrypted'] = $this->secretCipher->encrypt($pendingSecret);
        unset($state['secret']);
        unset($state['pending_secret_encrypted']);
        unset($state['pending_secret']);
        $this->saveState($userId, $state);

        return true;
    }

    public function disable(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        if (!(bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_NOT_ENABLED');
        }

        $secret = $this->resolveSecretFromState($state, 'secret_encrypted', 'secret');
        if ($secret === '' || !$this->isValidOtp($secret, $otpCode)) {
            return false;
        }

        $this->saveState($userId, ['enabled' => false]);

        return true;
    }

    public function verifyLoginOtp(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        if (!(bool) ($state['enabled'] ?? false)) {
            return true;
        }

        $before = $state;
        $secret = $this->resolveSecretFromState($state, 'secret_encrypted', 'secret');
        if ($secret === '') {
            return false;
        }

        if ($state !== $before) {
            $this->saveState($userId, $state);
        }

        return $this->isValidOtp($secret, $otpCode);
    }

    public function consumeRecoveryCode(string $userId, string $recoveryCode): bool
    {
        $state = $this->state($userId);
        if (!(bool) ($state['enabled'] ?? false)) {
            return false;
        }

        $normalized = $this->normalizeRecoveryCode($recoveryCode);
        if ($normalized === '') {
            return false;
        }

        $hashes = array_values(array_filter(
            (array) ($state['recovery_code_hashes'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));
        $legacyHashes = array_values(array_filter(
            (array) ($state['recovery_code_sha256'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));

        $matchedIndex = null;
        $matchedLegacyIndex = null;
        foreach ($hashes as $index => $hash) {
            if (password_verify($normalized, $hash)) {
                $matchedIndex = $index;
                break;
            }
        }
        if (!is_int($matchedIndex)) {
            $targetHash = hash('sha256', $normalized);
            foreach ($legacyHashes as $legacyIndex => $legacyHash) {
                if (hash_equals($legacyHash, $targetHash)) {
                    $matchedLegacyIndex = $legacyIndex;
                    break;
                }
            }
        }

        if (!is_int($matchedIndex) && !is_int($matchedLegacyIndex)) {
            return false;
        }

        if (is_int($matchedIndex)) {
            unset($hashes[$matchedIndex]);
        }
        if (is_int($matchedLegacyIndex)) {
            unset($legacyHashes[$matchedLegacyIndex]);
        }
        $state['recovery_code_hashes'] = array_values($hashes);
        if ($legacyHashes === []) {
            unset($state['recovery_code_sha256']);
        } else {
            $state['recovery_code_sha256'] = array_values($legacyHashes);
        }
        $this->saveState($userId, $state);

        return true;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(string $userId): array
    {
        $state = $this->state($userId);
        if (!(bool) ($state['enabled'] ?? false)) {
            throw new \RuntimeException('MFA_NOT_ENABLED');
        }

        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            $code = $this->generateRecoveryCode();
            $codes[] = $code;
            $hashes[] = password_hash($code, PASSWORD_ARGON2ID);
        }

        $state['recovery_code_hashes'] = $hashes;
        unset($state['recovery_code_sha256']);
        $this->saveState($userId, $state);

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $userId): array
    {
        $item = $this->cache->getItem($this->cacheKey($userId));
        $value = $item->get();

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $userId, array $state): void
    {
        $item = $this->cache->getItem($this->cacheKey($userId));
        $item->set($state);
        $this->cache->save($item);
    }

    private function cacheKey(string $userId): string
    {
        return 'auth_2fa_'.sha1($userId);
    }

    private function isValidOtp(string $secret, string $otpCode): bool
    {
        $code = trim($otpCode);
        if ($code === '') {
            return false;
        }

        $totp = TOTP::createFromSecret($secret);

        // Accept +/- 1 time-step (30s) to tolerate small clock drift between client and server.
        return $totp->verify($code, null, 29);
    }

    private function generateRecoveryCode(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(trim(str_replace('-', '', $code)));
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
