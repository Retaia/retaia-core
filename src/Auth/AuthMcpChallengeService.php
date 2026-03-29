<?php

namespace App\Auth;

use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;

final class AuthMcpChallengeService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private AuthMcpChallengeRepositoryInterface $challengeRepository,
        private AuthClientAdminService $adminService,
        private AuthClientPolicyService $policyService,
        private AgentRequestSignatureVerifier $signatureVerifier,
        private AuthMcpClientRegistrationService $registrationService,
    ) {
    }

    /**
     * @return array{status: string, payload?: array<string, mixed>}
     */
    public function createChallenge(string $clientId, string $openPgpFingerprint): array
    {
        if ($this->policyService->isMcpDisabledByAppPolicy()) {
            return ['status' => 'FORBIDDEN_SCOPE'];
        }

        $normalizedFingerprint = $this->registrationService->normalizeFingerprint($openPgpFingerprint);
        if ($clientId === '' || $normalizedFingerprint === null) {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $client = $this->registrationService->mcpClient($clientId, $normalizedFingerprint);
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

        $normalizedFingerprint = $this->registrationService->normalizeFingerprint($openPgpFingerprint);
        if ($clientId === '' || $normalizedFingerprint === null || $challengeId === '' || trim($signature) === '') {
            return ['status' => 'VALIDATION_FAILED'];
        }

        $client = $this->registrationService->mcpClient($clientId, $normalizedFingerprint);
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
