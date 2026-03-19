<?php

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

trait ApiAuthClientTrait
{
    private function authenticateClient(KernelBrowser $client, string $email, string $password = FixtureUsers::DEFAULT_PASSWORD): void
    {
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $token = $payload['access_token'] ?? null;
        self::assertIsString($token);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    private function attachDefaultAgentSignatureHeaders(
        KernelBrowser $client,
        string $agentId = '11111111-1111-4111-8111-111111111111',
        string $fingerprint = 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
    ): void {
        $client->setServerParameter('HTTP_X_RETAIA_AGENT_ID', $agentId);
        $client->setServerParameter('HTTP_X_RETAIA_OPENPGP_FINGERPRINT', $fingerprint);
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE', 'test-signature');
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE_TIMESTAMP', '2026-03-19T12:00:00+00:00');
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE_NONCE', 'test-nonce');
    }
}
