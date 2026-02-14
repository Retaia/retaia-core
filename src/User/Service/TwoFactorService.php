<?php

namespace App\User\Service;

use Psr\Cache\CacheItemPoolInterface;

final class TwoFactorService
{
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

        $secret = strtoupper(bin2hex(random_bytes(10)));
        $state['pending_secret'] = $secret;
        $this->saveState($userId, $state);

        return [
            'secret' => $secret,
            'otpauth_uri' => sprintf(
                'otpauth://totp/%s:%s?secret=%s&issuer=%s',
                rawurlencode('Retaia'),
                rawurlencode($email),
                $secret,
                rawurlencode('Retaia')
            ),
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
        return hash_equals($this->expectedOtp($secret), trim($otpCode));
    }

    private function expectedOtp(string $secret): string
    {
        return substr($secret, -6);
    }
}
