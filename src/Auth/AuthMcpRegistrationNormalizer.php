<?php

namespace App\Auth;

final class AuthMcpRegistrationNormalizer
{
    public function normalizeFingerprint(string $fingerprint): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');
        if ($normalized === '' || preg_match('/^[A-F0-9]{40}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    public function normalizePublicKey(string $openPgpPublicKey): ?string
    {
        $normalized = trim($openPgpPublicKey);

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeLabel(?string $label): ?string
    {
        $normalized = trim((string) $label);

        return $normalized !== '' ? $normalized : null;
    }
}
