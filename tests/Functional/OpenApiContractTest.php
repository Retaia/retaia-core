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

    public function testAuthEndpointsDeclareErrorModelInOpenApi(): void
    {
        $openApi = $this->openApi();

        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '401');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '403');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/me', 'get', '401');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/request', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/request', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/reset', 'post', '400');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/reset', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/request', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/request', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/confirm', 'post', '400');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/confirm', 'post', '422');
    }

    public function testErrorCodeEnumIncludesRuntimeCodes(): void
    {
        $openApi = $this->openApi();
        $errorSchema = $this->errorSchema($openApi);
        $errorCodes = $this->errorCodes($errorSchema);

        $runtimeCodes = [
            'UNAUTHORIZED',
            'FORBIDDEN_SCOPE',
            'FORBIDDEN_ACTOR',
            'EMAIL_NOT_VERIFIED',
            'STATE_CONFLICT',
            'IDEMPOTENCY_CONFLICT',
            'MISSING_IDEMPOTENCY_KEY',
            'STALE_LOCK_TOKEN',
            'NAME_COLLISION_EXHAUSTED',
            'PURGED',
            'NOT_FOUND',
            'INVALID_TOKEN',
            'USER_NOT_FOUND',
            'VALIDATION_FAILED',
            'LOCK_REQUIRED',
            'LOCK_INVALID',
            'TOO_MANY_ATTEMPTS',
            'RATE_LIMITED',
            'TEMPORARY_UNAVAILABLE',
        ];

        foreach ($runtimeCodes as $code) {
            self::assertContains($code, $errorCodes, sprintf('OpenAPI ErrorResponse enum must include %s.', $code));
        }
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

    public function testAuthUnauthorizedErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testAuthValidationErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testAuthRateLimitErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $status = Response::HTTP_ACCEPTED;
        for ($i = 0; $i < 6; ++$i) {
            $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
                'email' => 'admin@retaia.local',
            ]);
            $status = $client->getResponse()->getStatusCode();
            if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
                break;
            }
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
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
     */
    private function assertPathStatusUsesErrorResponse(array $openApi, string $path, string $method, string $status): void
    {
        $schemaRef = $openApi['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null;
        self::assertSame(
            '#/components/schemas/ErrorResponse',
            $schemaRef,
            sprintf('OpenAPI path %s %s response %s must reference ErrorResponse.', strtoupper($method), $path, $status)
        );
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

    /**
     * @param mixed $payload
     * @param array<int, string> $errorCodes
     */
    private function assertErrorPayloadMatchesModel(mixed $payload, array $errorCodes): void
    {
        self::assertIsArray($payload);
        self::assertIsString($payload['code'] ?? null);
        self::assertContains((string) $payload['code'], $errorCodes);
        self::assertIsString($payload['message'] ?? null);
        self::assertIsBool($payload['retryable'] ?? null);
        self::assertIsString($payload['correlation_id'] ?? null);
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
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS app_user (id VARCHAR(32) NOT NULL PRIMARY KEY, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles CLOB NOT NULL, email_verified BOOLEAN NOT NULL DEFAULT 0)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_app_user_email ON app_user (email)');
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
