<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;

final class AuthMcpClientRegistrationService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
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

        $client = $this->mcpClient($clientId, $normalizedFingerprint, false);
        if (!$client instanceof AuthClientRegistryEntry) {
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

    public function mcpClient(string $clientId, string $openPgpFingerprint, bool $checkFingerprint = true): ?AuthClientRegistryEntry
    {
        $client = $this->registryRepository->findByClientId($clientId);
        if (!$client instanceof AuthClientRegistryEntry) {
            return null;
        }

        if ($client->clientKind !== ClientKind::MCP) {
            return null;
        }

        if ($checkFingerprint && !hash_equals((string) ($client->openPgpFingerprint ?? ''), $openPgpFingerprint)) {
            return null;
        }

        return $client;
    }

    public function normalizeFingerprint(string $fingerprint): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');
        if ($normalized === '' || preg_match('/^[A-F0-9]{40}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
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

    private function normalizeLabel(?string $label): ?string
    {
        $normalized = trim((string) $label);

        return $normalized !== '' ? $normalized : null;
    }
}
