<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;

final class AuthMcpService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private AuthClientStateStore $stateStore,
        private AuthClientAdminService $adminService,
        private AuthClientPolicyService $policyService,
    ) {
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function register(string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizeFingerprint($openPgpFingerprint);
        $normalizedPublicKey = trim($openPgpPublicKey);
        if ($normalizedFingerprint === null || $normalizedPublicKey === '') {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $normalizedLabel = $this->normalizeLabel($clientLabel);
        $registry = $this->stateStore->registry();

        foreach ($registry as $clientId => $client) {
            if (!is_array($client) || (string) ($client['client_kind'] ?? '') !== ClientKind::MCP) {
                continue;
            }

            if (!hash_equals((string) ($client['openpgp_fingerprint'] ?? ''), $normalizedFingerprint)) {
                continue;
            }

            $registeredAt = (string) ($client['registered_at'] ?? $now);
            $rotatedAt = null;
            if (!hash_equals((string) ($client['openpgp_public_key'] ?? ''), $normalizedPublicKey)) {
                $rotatedAt = $now;
            }

            $client['openpgp_public_key'] = $normalizedPublicKey;
            $client['openpgp_fingerprint'] = $normalizedFingerprint;
            $client['registered_at'] = $registeredAt;
            $client['rotated_at'] = $rotatedAt;
            if ($normalizedLabel !== null) {
                $client['client_label'] = $normalizedLabel;
            }

            $registry[(string) $clientId] = $client;
            $this->stateStore->saveRegistry($registry);

            return [
                'status' => 'SUCCESS',
                'payload' => $this->registrationPayload((string) $clientId, $client),
            ];
        }

        $clientId = 'mcp-'.bin2hex(random_bytes(6));
        $registry[$clientId] = array_filter([
            'client_kind' => ClientKind::MCP,
            'client_label' => $normalizedLabel,
            'openpgp_public_key' => $normalizedPublicKey,
            'openpgp_fingerprint' => $normalizedFingerprint,
            'registered_at' => $now,
            'rotated_at' => null,
        ], static fn (mixed $value): bool => $value !== null);
        $this->stateStore->saveRegistry($registry);

        return [
            'status' => 'SUCCESS',
            'payload' => $this->registrationPayload($clientId, $registry[$clientId]),
        ];
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function rotateKey(string $clientId, string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizeFingerprint($openPgpFingerprint);
        $normalizedPublicKey = trim($openPgpPublicKey);
        if ($clientId === '' || $normalizedFingerprint === null || $normalizedPublicKey === '') {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $registry = $this->stateStore->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client) || (string) ($client['client_kind'] ?? '') !== ClientKind::MCP) {
            return ['status' => 'STATE_CONFLICT'];
        }

        foreach ($registry as $registeredClientId => $registeredClient) {
            if (!is_string($registeredClientId) || $registeredClientId === $clientId || !is_array($registeredClient)) {
                continue;
            }

            if ((string) ($registeredClient['client_kind'] ?? '') !== ClientKind::MCP) {
                continue;
            }

            if (hash_equals((string) ($registeredClient['openpgp_fingerprint'] ?? ''), $normalizedFingerprint)) {
                return ['status' => 'STATE_CONFLICT'];
            }
        }

        $client['openpgp_public_key'] = $normalizedPublicKey;
        $client['openpgp_fingerprint'] = $normalizedFingerprint;
        $client['rotated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        if (($client['registered_at'] ?? null) === null) {
            $client['registered_at'] = $client['rotated_at'];
        }

        $normalizedLabel = $this->normalizeLabel($clientLabel);
        if ($normalizedLabel !== null) {
            $client['client_label'] = $normalizedLabel;
        }

        $registry[$clientId] = $client;
        $this->stateStore->saveRegistry($registry);

        return [
            'status' => 'SUCCESS',
            'payload' => $this->registrationPayload($clientId, $client),
        ];
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function createChallenge(string $clientId, string $openPgpFingerprint): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizeFingerprint($openPgpFingerprint);
        if ($clientId === '' || $normalizedFingerprint === null) {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $client = $this->mcpClient($clientId, $normalizedFingerprint);
        if ($client === null) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $challengeId = 'mcpc_'.bin2hex(random_bytes(12));
        $challenge = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $challenges = $this->activeChallenges();
        $challenges[$challengeId] = [
            'client_id' => $clientId,
            'openpgp_fingerprint' => $normalizedFingerprint,
            'challenge' => $challenge,
            'expires_at' => time() + self::CHALLENGE_TTL_SECONDS,
            'used' => false,
        ];
        $this->stateStore->saveMcpChallenges($challenges);

        return [
            'status' => 'SUCCESS',
            'payload' => [
                'challenge_id' => $challengeId,
                'challenge' => $challenge,
                'expires_in' => self::CHALLENGE_TTL_SECONDS,
            ],
        ];
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function mintToken(string $clientId, string $openPgpFingerprint, string $challengeId, string $signature): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->normalizeFingerprint($openPgpFingerprint);
        if ($clientId === '' || $normalizedFingerprint === null || $challengeId === '' || trim($signature) === '') {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $client = $this->mcpClient($clientId, $normalizedFingerprint);
        if ($client === null) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $challenges = $this->activeChallenges();
        $challenge = $challenges[$challengeId] ?? null;
        if (!is_array($challenge)) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $invalidChallenge = (bool) ($challenge['used'] ?? false)
            || !hash_equals((string) ($challenge['client_id'] ?? ''), $clientId)
            || !hash_equals((string) ($challenge['openpgp_fingerprint'] ?? ''), $normalizedFingerprint);
        if ($invalidChallenge) {
            unset($challenges[$challengeId]);
            $this->stateStore->saveMcpChallenges($challenges);

            return ['status' => 'UNAUTHORIZED'];
        }

        if (!$this->isValidSignature(trim($signature), (string) ($challenge['challenge'] ?? ''), $client)) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $challenge['used'] = true;
        $challenge['used_at'] = time();
        $challenges[$challengeId] = $challenge;
        $this->stateStore->saveMcpChallenges($challenges);

        $tokenPayload = $this->adminService->mintRegisteredClientToken($clientId);
        if (!is_array($tokenPayload)) {
            return ['status' => 'UNAUTHORIZED'];
        }

        return [
            'status' => 'SUCCESS',
            'payload' => $tokenPayload,
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private function registrationPayload(string $clientId, array $client): array
    {
        $payload = [
            'client_id' => $clientId,
            'client_kind' => ClientKind::MCP,
            'openpgp_fingerprint' => (string) ($client['openpgp_fingerprint'] ?? ''),
            'registered_at' => (string) ($client['registered_at'] ?? ''),
        ];

        $rotatedAt = $client['rotated_at'] ?? null;
        if (is_string($rotatedAt) && $rotatedAt !== '') {
            $payload['rotated_at'] = $rotatedAt;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mcpClient(string $clientId, string $openPgpFingerprint): ?array
    {
        $registry = $this->stateStore->registry();
        $client = $registry[$clientId] ?? null;
        if (!is_array($client)) {
            return null;
        }

        if ((string) ($client['client_kind'] ?? '') !== ClientKind::MCP) {
            return null;
        }

        if (!hash_equals((string) ($client['openpgp_fingerprint'] ?? ''), $openPgpFingerprint)) {
            return null;
        }

        return $client;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function activeChallenges(): array
    {
        $active = [];
        foreach ($this->stateStore->mcpChallenges() as $challengeId => $challenge) {
            if (!is_string($challengeId) || !is_array($challenge)) {
                continue;
            }

            if ((int) ($challenge['expires_at'] ?? 0) < time()) {
                continue;
            }

            $active[$challengeId] = $challenge;
        }

        return $active;
    }

    private function normalizeFingerprint(string $fingerprint): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');
        if ($normalized === '' || preg_match('/^[A-F0-9]{40}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeLabel(?string $label): ?string
    {
        $normalized = trim((string) $label);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Lightweight signature validation until a dedicated OpenPGP library is introduced.
     *
     * @param array<string, mixed> $client
     */
    private function isValidSignature(string $signature, string $challenge, array $client): bool
    {
        $publicKey = (string) ($client['openpgp_public_key'] ?? '');
        $fingerprint = (string) ($client['openpgp_fingerprint'] ?? '');
        if ($publicKey === '' || $fingerprint === '' || $challenge === '') {
            return false;
        }

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $challenge, $publicKey.'|'.$fingerprint, true)), '+/', '-_'), '=');

        return hash_equals($expected, $signature);
    }
}
