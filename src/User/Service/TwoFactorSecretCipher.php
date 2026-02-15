<?php

namespace App\User\Service;

final class TwoFactorSecretCipher
{
    /**
     * @var array<string, string>
     */
    private array $keysByVersion;

    public function __construct(
        private string $keyringConfig,
        private string $activeVersion,
    ) {
        $this->keysByVersion = $this->parseKeyring($this->keyringConfig);
        if (!isset($this->keysByVersion[$this->activeVersion])) {
            throw new \InvalidArgumentException('Active 2FA key version is missing from keyring.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->keysByVersion[$this->activeVersion];
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return $this->activeVersion.':'.base64_encode($nonce).':'.base64_encode($ciphertext);
    }

    public function decrypt(string $payload): ?string
    {
        [$version, $nonce, $ciphertext] = $this->splitPayload($payload);
        if ($version === null || $nonce === null || $ciphertext === null) {
            return null;
        }

        $key = $this->keysByVersion[$version] ?? null;
        if (!is_string($key)) {
            return null;
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if (!is_string($plaintext)) {
            return null;
        }

        return $plaintext;
    }

    public function needsRotation(string $payload): bool
    {
        [$version] = $this->splitPayload($payload);
        if ($version === null) {
            return false;
        }

        return $version !== $this->activeVersion;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function splitPayload(string $payload): array
    {
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            return [null, null, null];
        }

        $version = trim($parts[0]);
        if ($version === '') {
            return [null, null, null];
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);
        if (!is_string($nonce) || !is_string($ciphertext)) {
            return [null, null, null];
        }
        if (strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $ciphertext === '') {
            return [null, null, null];
        }

        return [$version, $nonce, $ciphertext];
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyring(string $config): array
    {
        $entries = array_filter(array_map('trim', explode(',', $config)), static fn (string $entry): bool => $entry !== '');
        $parsed = [];
        foreach ($entries as $entry) {
            $parts = explode(':', $entry, 2);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException('Invalid 2FA keyring entry format.');
            }
            $version = trim($parts[0]);
            $encodedKey = trim($parts[1]);
            if ($version === '' || $encodedKey === '') {
                throw new \InvalidArgumentException('Invalid 2FA keyring entry content.');
            }
            $decodedKey = base64_decode($encodedKey, true);
            if (!is_string($decodedKey) || strlen($decodedKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \InvalidArgumentException('Invalid 2FA keyring key material.');
            }
            $parsed[$version] = $decodedKey;
        }

        if ($parsed === []) {
            throw new \InvalidArgumentException('2FA keyring cannot be empty.');
        }

        return $parsed;
    }
}
