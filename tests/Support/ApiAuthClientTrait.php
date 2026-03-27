<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
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
        ?string $fingerprint = null,
    ): void {
        $material = AgentSigningTestHelper::publicMaterial();
        $fingerprint ??= $material['fingerprint'];

        $client->setServerParameter('HTTP_X_RETAIA_AGENT_ID', $agentId);
        $client->setServerParameter('HTTP_X_RETAIA_OPENPGP_FINGERPRINT', $fingerprint);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $extraHeaders
     */
    private function signedJsonRequestAsAgent(KernelBrowser $client, string $method, string $uri, array $payload = [], array $extraHeaders = []): void
    {
        $this->ensureAgentRuntimeTableExists();
        $headers = array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            AgentSigningTestHelper::signedHeaders($method, $uri, $payload),
            $extraHeaders,
        );

        $client->request(
            strtoupper($method),
            $uri,
            [],
            [],
            $headers,
            (string) json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function registerDefaultAgent(KernelBrowser $client): void
    {
        $material = AgentSigningTestHelper::publicMaterial();
        $payload = [
            'agent_id' => $material['agent_id'],
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => $material['public_key'],
            'openpgp_fingerprint' => $material['fingerprint'],
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts', 'generate_preview', 'generate_thumbnails', 'generate_audio_waveform', 'transcribe_audio'],
        ];

        $this->signedJsonRequestAsAgent($client, 'POST', '/api/v1/agents/register', $payload);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    private function ensureAgentRuntimeTableExists(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE TABLE IF NOT EXISTS agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_agent_runtime_last_seen_at ON agent_runtime (last_seen_at)');
    }
}
