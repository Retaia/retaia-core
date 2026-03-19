<?php

namespace App\Api\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SignedAgentRequestValidator
{
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
            new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return $this->unauthorizedResponse(['X-Retaia-Signature-Timestamp']);
        }

        $agentId = trim((string) ($payload['agent_id'] ?? ''));
        $headerAgentId = trim((string) $request->headers->get('X-Retaia-Agent-Id', ''));
        if ($agentId !== '' && $headerAgentId !== '' && $agentId !== $headerAgentId) {
            return $this->unauthorizedResponse(['X-Retaia-Agent-Id']);
        }

        $fingerprint = trim((string) ($payload['openpgp_fingerprint'] ?? ''));
        $headerFingerprint = trim((string) $request->headers->get('X-Retaia-OpenPGP-Fingerprint', ''));
        if ($fingerprint !== '' && $headerFingerprint !== '' && $fingerprint !== $headerFingerprint) {
            return $this->unauthorizedResponse(['X-Retaia-OpenPGP-Fingerprint']);
        }

        return null;
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
