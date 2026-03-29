<?php

namespace App\Auth;

use App\Domain\AuthClient\ClientKind;

final class AuthMcpClientRegistryService
{
    public function __construct(
        private AuthClientRegistryRepositoryInterface $registryRepository,
    ) {
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function register(string $normalizedPublicKey, string $normalizedFingerprint, ?string $normalizedLabel = null): array
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
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
    public function rotateKey(string $clientId, string $normalizedPublicKey, string $normalizedFingerprint, ?string $normalizedLabel = null): array
    {
        if ($clientId === '') {
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
}
