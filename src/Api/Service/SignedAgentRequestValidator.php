<?php

namespace App\Api\Service;

use App\Api\Service\AgentSignature\AgentPublicKeyRepositoryInterface;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepositoryInterface;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SignedAgentRequestValidator
{
    private const SIGNATURE_TTL_SECONDS = 300;

    public function __construct(
        private AgentPublicKeyRepositoryInterface $publicKeyRepository,
        private AgentRequestSignatureVerifier $signatureVerifier,
        private AgentSignatureNonceRepositoryInterface $nonceRepository,
        private SignedAgentMessageCanonicalizer $messageCanonicalizer,
        private AgentRuntimeStore $agentRuntimeStore,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function violationResponse(Request $request, array $payload = []): ?JsonResponse
    {
        $requiredHeaders = [
            'X-Retaia-Agent-Id',
            'X-Retaia-OpenPGP-Fingerprint',
            'X-Retaia-Signature',
            'X-Retaia-Signature-Timestamp',
            'X-Retaia-Signature-Nonce',
        ];

        $missingHeaders = [];
        foreach ($requiredHeaders as $header) {
            if (trim((string) $request->headers->get($header, '')) === '') {
                $missingHeaders[] = $header;
            }
        }

        if ($missingHeaders !== []) {
            return $this->unauthorizedResponse($missingHeaders);
        }

        $timestamp = trim((string) $request->headers->get('X-Retaia-Signature-Timestamp', ''));
        try {
            $signatureTime = new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return $this->unauthorizedResponse(['X-Retaia-Signature-Timestamp']);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if (abs($now->getTimestamp() - $signatureTime->getTimestamp()) > self::SIGNATURE_TTL_SECONDS) {
            return $this->unauthorizedResponse(['X-Retaia-Signature-Timestamp']);
        }

        $agentId = trim((string) ($payload['agent_id'] ?? ''));
        $headerAgentId = trim((string) $request->headers->get('X-Retaia-Agent-Id', ''));
        if ($agentId !== '' && $headerAgentId !== '' && $agentId !== $headerAgentId) {
            return $this->unauthorizedResponse(['X-Retaia-Agent-Id']);
        }

        $fingerprint = $this->normalizeFingerprint((string) ($payload['openpgp_fingerprint'] ?? ''));
        $headerFingerprint = trim((string) $request->headers->get('X-Retaia-OpenPGP-Fingerprint', ''));
        $normalizedHeaderFingerprint = $this->normalizeFingerprint($headerFingerprint);
        if ($fingerprint !== null && $normalizedHeaderFingerprint !== null && $fingerprint !== $normalizedHeaderFingerprint) {
            return $this->unauthorizedResponse(['X-Retaia-OpenPGP-Fingerprint']);
        }
        if ($normalizedHeaderFingerprint === null) {
            return $this->unauthorizedResponse(['X-Retaia-OpenPGP-Fingerprint']);
        }

        $publicKey = trim((string) ($payload['openpgp_public_key'] ?? ''));
        if ($publicKey === '') {
            $publicKey = (string) ($this->publicKeyRepository->findByAgentIdAndFingerprint($headerAgentId, $normalizedHeaderFingerprint)?->publicKey ?? '');
        }
        if ($publicKey === '') {
            return $this->unauthorizedResponse(['X-Retaia-OpenPGP-Fingerprint']);
        }

        $signature = trim((string) $request->headers->get('X-Retaia-Signature', ''));
        $canonicalMessage = $this->messageCanonicalizer->canonicalize($request);
        if (!$this->signatureVerifier->verify($publicKey, $normalizedHeaderFingerprint, $canonicalMessage, $signature)) {
            return $this->unauthorizedResponse(['X-Retaia-Signature']);
        }

        $nonce = trim((string) $request->headers->get('X-Retaia-Signature-Nonce', ''));
        if (!$this->nonceRepository->consume($headerAgentId, $nonce, self::SIGNATURE_TTL_SECONDS)) {
            return $this->unauthorizedResponse(['X-Retaia-Signature-Nonce']);
        }

        $this->agentRuntimeStore->touchSeen($headerAgentId);

        return null;
    }

    private function normalizeFingerprint(string $fingerprint): ?string
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($fingerprint)) ?? '');
        if ($normalized === '' || preg_match('/^[A-F0-9]{40}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $invalidHeaders
     */
    private function unauthorizedResponse(array $invalidHeaders): JsonResponse
    {
        return new JsonResponse(
            [
                'code' => 'UNAUTHORIZED',
                'message' => 'Signed agent request headers are required',
                'details' => [
                    'invalid_headers' => array_values($invalidHeaders),
                ],
            ],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
