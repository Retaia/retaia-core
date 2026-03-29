<?php

namespace App\Auth;

final class AuthMcpService
{
    public function __construct(
        private AuthMcpClientRegistrationService $registrationService,
        private AuthMcpChallengeService $challengeService,
    ) {
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function register(string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        return $this->registrationService->register($openPgpPublicKey, $openPgpFingerprint, $clientLabel);
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function rotateKey(string $clientId, string $openPgpPublicKey, string $openPgpFingerprint, ?string $clientLabel = null): array
    {
        return $this->registrationService->rotateKey($clientId, $openPgpPublicKey, $openPgpFingerprint, $clientLabel);
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function createChallenge(string $clientId, string $openPgpFingerprint): array
    {
        return $this->challengeService->createChallenge($clientId, $openPgpFingerprint);
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function mintToken(string $clientId, string $openPgpFingerprint, string $challengeId, string $signature): array
    {
        return $this->challengeService->mintToken($clientId, $openPgpFingerprint, $challengeId, $signature);
    }
}
