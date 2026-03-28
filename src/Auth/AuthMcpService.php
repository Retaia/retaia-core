<?php

namespace App\Auth;

use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Domain\AuthClient\ClientKind;

final class AuthMcpService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
        private AuthMcpChallengeRepositoryInterface $challengeRepository,
        private AuthClientAdminService $adminService,
        private AuthClientPolicyService $policyService,
        private AgentRequestSignatureVerifier $signatureVerifier,
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
        foreach ($this->registryRepository->findAll() as $client) {
            if ($client->clientKind !== ClientKind::MCP) {
                continue;
            }

            if (!hash_equals((string) ($client->openPgpFingerprint ?? ''), $normalizedFingerprint)) {
                continue;
            }

            $registeredAt = (string) ($client->registeredAt ?? $now);
            $rotatedAt = null;
            if (!hash_equals((string) ($client->openPgpPublicKey ?? ''), $normalizedPublicKey)) {
                $rotatedAt = $now;
            }
            $updated = new AuthClientRegistryEntry(
                $client->clientId,
                $client->clientKind,
                $client->secretKey,
                $normalizedLabel ?? $client->clientLabel,
                $normalizedPublicKey,
                $normalizedFingerprint,
                $registeredAt,
                $rotatedAt,
            );
            $this->registryRepository->save($updated);

            return [
                'status' => 'SUCCESS',
                'payload' => $this->registrationPayload($updated),
            ];
        }

        $clientId = 'mcp-'.bin2hex(random_bytes(6));
        $created = new AuthClientRegistryEntry(
            $clientId,
            ClientKind::MCP,
            null,
            $normalizedLabel,
            $normalizedPublicKey,
            $normalizedFingerprint,
            $now,
            null,
        );
        $this->registryRepository->save($created);

        return [
            'status' => 'SUCCESS',
            'payload' => $this->registrationPayload($created),
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

        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry || $client->clientKind !== ClientKind::MCP) {
            return ['status' => 'STATE_CONFLICT'];
        }

        foreach ($this->registryRepository->findAll() as $registeredClient) {
            if ($registeredClient->clientId === $clientId) {
                continue;
            }
            if ($registeredClient->clientKind !== ClientKind::MCP) {
                continue;
            }
            if (hash_equals((string) ($registeredClient->openPgpFingerprint ?? ''), $normalizedFingerprint)) {
                return ['status' => 'STATE_CONFLICT'];
            }
        }

        $rotatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $normalizedLabel = $this->normalizeLabel($clientLabel);
        $updated = new AuthClientRegistryEntry(
            $client->clientId,
            $client->clientKind,
            $client->secretKey,
            $normalizedLabel ?? $client->clientLabel,
            $normalizedPublicKey,
            $normalizedFingerprint,
            $client->registeredAt ?? $rotatedAt,
            $rotatedAt,
        );
        $this->registryRepository->save($updated);

        return [
            'status' => 'SUCCESS',
            'payload' => $this->registrationPayload($updated),
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

        $this->challengeRepository->save(new AuthMcpChallenge(
            $challengeId,
            $clientId,
            $normalizedFingerprint,
            $challenge,
            time() + self::CHALLENGE_TTL_SECONDS,
            false,
            null,
        ));

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

        $challenge = $this->challengeRepository->findByChallengeId($challengeId);
        if (!$challenge instanceof AuthMcpChallenge || $challenge->expiresAt < time()) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $invalidChallenge = $challenge->used
            || !hash_equals($challenge->clientId, $clientId)
            || !hash_equals($challenge->openPgpFingerprint, $normalizedFingerprint);
        if ($invalidChallenge) {
            $this->challengeRepository->delete($challengeId);

            return ['status' => 'UNAUTHORIZED'];
        }

        if (!$this->isValidSignature(trim($signature), $challenge->challenge, $client)) {
            return ['status' => 'UNAUTHORIZED'];
        }

        $this->challengeRepository->save(new AuthMcpChallenge(
            $challenge->challengeId,
            $challenge->clientId,
            $challenge->openPgpFingerprint,
            $challenge->challenge,
            $challenge->expiresAt,
            true,
            time(),
        ));

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
     */
    private function registrationPayload(AuthClientRegistryEntry $client): array
    {
        $payload = [
            'client_id' => $client->clientId,
            'client_kind' => ClientKind::MCP,
            'openpgp_fingerprint' => (string) ($client->openPgpFingerprint ?? ''),
            'registered_at' => (string) ($client->registeredAt ?? ''),
        ];

        $rotatedAt = $client->rotatedAt;
        if (is_string($rotatedAt) && $rotatedAt !== '') {
            $payload['rotated_at'] = $rotatedAt;
        }

        return $payload;
    }

    /**
     */
    private function mcpClient(string $clientId, string $openPgpFingerprint): ?AuthClientRegistryEntry
    {
        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry) {
            return null;
        }

        if ($client->clientKind !== ClientKind::MCP) {
            return null;
        }

        if (!hash_equals((string) ($client->openPgpFingerprint ?? ''), $openPgpFingerprint)) {
            return null;
        }

        return $client;
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

    private function isValidSignature(string $signature, string $challenge, AuthClientRegistryEntry $client): bool
    {
        $publicKey = (string) ($client->openPgpPublicKey ?? '');
        $fingerprint = (string) ($client->openPgpFingerprint ?? '');
        if ($publicKey === '' || $fingerprint === '' || $challenge === '') {
            return false;
        }

        return $this->signatureVerifier->verify($publicKey, $fingerprint, $challenge, $signature);
    }
}
