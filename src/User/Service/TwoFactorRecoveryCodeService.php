<?php

namespace App\User\Service;

final class TwoFactorRecoveryCodeService
{
    private const RECOVERY_CODE_COUNT = 10;

    /**
     * @param array<string, mixed> $state
     */
    public function consumeRecoveryCode(array &$state, string $recoveryCode): bool
    {
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

        return true;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<string>
     */
    public function regenerateRecoveryCodes(array &$state): array
    {
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

        return $codes;
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
