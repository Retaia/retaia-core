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
}
