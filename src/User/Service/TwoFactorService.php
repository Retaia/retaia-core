<?php

namespace App\User\Service;

use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;

final class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private CacheItemPoolInterface $cache,
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

        return is_string($state['pending_secret'] ?? null) && $state['pending_secret'] !== '';
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
        $state['pending_secret'] = $secret;
        $this->saveState($userId, $state);

        return [
            'secret' => $secret,
            'otpauth_uri' => $totp->getProvisioningUri(),
        ];
    }

    public function enable(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        $pendingSecret = (string) ($state['pending_secret'] ?? '');
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
        $state['secret'] = $pendingSecret;
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

        $secret = (string) ($state['secret'] ?? '');
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

        $secret = (string) ($state['secret'] ?? '');
        if ($secret === '') {
            return false;
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

        $targetHash = hash('sha256', $normalized);
        $hashes = array_values(array_filter(
            (array) ($state['recovery_code_hashes'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));

        $matchedIndex = null;
        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $targetHash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if (!is_int($matchedIndex)) {
            return false;
        }

        unset($hashes[$matchedIndex]);
        $state['recovery_code_hashes'] = array_values($hashes);
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
            $hashes[] = hash('sha256', $code);
        }

        $state['recovery_code_hashes'] = $hashes;
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
}
