<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

final class OpenApiContractTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testCriticalEndpointsDeclareIdempotencyHeaderInOpenApi(): void
    {
        $openApi = $this->openApi();

        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/decision', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/reprocess', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/jobs/{job_id}/submit', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/jobs/{job_id}/fail', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/batches/moves', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/decisions/apply', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/purge', 'post');
    }

    public function testErrorResponseRuntimeMatchesOpenApiErrorModel(): void
    {
        $openApi = $this->openApi();
        $errorSchema = $this->errorSchema($openApi);
        $errorCodes = $this->errorCodes($errorSchema);

        $client = $this->createAuthenticatedClient();
        $this->ensureAuxiliaryTables();
        $this->seedAsset('11111111-1111-1111-1111-111111111111', AssetState::PROCESSED);

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/decision', [
            'action' => 'KEEP',
        ], [
            'HTTP_IDEMPOTENCY_KEY' => 'contract-decision-conflict-1',
            'HTTP_X_CORRELATION_ID' => 'contract-correlation-id',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['code'] ?? null);
        self::assertContains((string) $payload['code'], $errorCodes);
        self::assertIsString($payload['message'] ?? null);
        self::assertIsBool($payload['retryable'] ?? null);
        self::assertSame('contract-correlation-id', $payload['correlation_id'] ?? null);
    }

    public function testDecisionRequestWithoutIdempotencyKeyIsRejected(): void
    {
        $openApi = $this->openApi();
        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/decision', 'post');

        $client = $this->createAuthenticatedClient();
        $this->ensureAuxiliaryTables();
        $this->seedAsset('22222222-2222-2222-2222-222222222222', AssetState::DECISION_PENDING);

        $client->jsonRequest('POST', '/api/v1/assets/22222222-2222-2222-2222-222222222222/decision', [
            'action' => 'KEEP',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MISSING_IDEMPOTENCY_KEY', $payload['code'] ?? null);
        self::assertArrayHasKey('retryable', $payload);
        self::assertArrayHasKey('correlation_id', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi/v1.yaml');

        return $parsed;
    }

    /**
     * @param array<string, mixed> $openApi
     */
    private function assertPathHasIdempotencyHeader(array $openApi, string $path, string $method): void
    {
        $parameters = $openApi['paths'][$path][$method]['parameters'] ?? null;
        self::assertIsArray($parameters, sprintf('OpenAPI path %s %s must define parameters.', strtoupper($method), $path));

        $hasReference = false;
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            if (($parameter['$ref'] ?? null) === '#/components/parameters/IdempotencyKey') {
                $hasReference = true;
                break;
            }
        }

        self::assertTrue($hasReference, sprintf('OpenAPI path %s %s must reference IdempotencyKey.', strtoupper($method), $path));
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function errorSchema(array $openApi): array
    {
        $schema = $openApi['components']['schemas']['ErrorResponse'] ?? null;
        self::assertIsArray($schema);
        self::assertSame(['code', 'message', 'retryable', 'correlation_id'], $schema['required'] ?? null);

        return $schema;
    }

    /**
     * @param array<string, mixed> $errorSchema
     * @return array<int, string>
     */
    private function errorCodes(array $errorSchema): array
    {
        $enum = $errorSchema['properties']['code']['enum'] ?? null;
        self::assertIsArray($enum);

        return array_values(array_map('strval', $enum));
    }

    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $client;
    }

    private function ensureAuxiliaryTables(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS idempotency_entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, actor_id VARCHAR(64) NOT NULL, method VARCHAR(8) NOT NULL, path VARCHAR(255) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_status INTEGER NOT NULL, response_body CLOB NOT NULL, created_at DATETIME NOT NULL)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_idempotency_key_scope ON idempotency_entry (actor_id, method, path, idempotency_key)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS asset_operation_lock (id VARCHAR(32) PRIMARY KEY NOT NULL, asset_uuid VARCHAR(36) NOT NULL, lock_type VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, acquired_at DATETIME NOT NULL, released_at DATETIME DEFAULT NULL)');
    }

    private function seedAsset(string $uuid, AssetState $state): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, $uuid) instanceof Asset) {
            return;
        }

        $asset = new Asset($uuid, 'VIDEO', 'contract.mov', $state);
        $entityManager->persist($asset);
        $entityManager->flush();
    }
}
