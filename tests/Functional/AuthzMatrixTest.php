<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\FixtureUsers;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthzMatrixTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testAnonymousActorGetsUnauthorizedForMutatingAssetEndpoint(): void
    {
        $client = static::createClient();
        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', ['notes' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testAgentGetsForbiddenActorOnHumanAssetMutation(): void
    {
        $client = $this->createAgentClient();
        $this->seedDecisionPendingAsset();

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', ['notes' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    #[DataProvider('jobEndpointMatrixProvider')]
    public function testAdminGetsForbiddenScopeOnAgentJobEndpoints(string $method, string $uri, array $payload = []): void
    {
        $client = $this->createAdminClient();

        $this->requestJson($client, $method, $uri, $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    #[DataProvider('jobEndpointMatrixProvider')]
    public function testAnonymousActorGetsUnauthorizedOnAgentJobEndpoints(string $method, string $uri, array $payload = []): void
    {
        $client = static::createClient();

        $this->requestJson($client, $method, $uri, $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    #[DataProvider('opsEndpointMatrixProvider')]
    public function testAnonymousActorGetsUnauthorizedOnOpsEndpoints(string $method, string $uri, array $payload = []): void
    {
        $client = static::createClient();

        $this->requestJson($client, $method, $uri, $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $error = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $error['code'] ?? null);
    }

    #[DataProvider('opsEndpointMatrixProvider')]
    public function testAgentGetsForbiddenActorOnOpsEndpoints(string $method, string $uri, array $payload = []): void
    {
        $client = $this->createAgentClient();

        $this->requestJson($client, $method, $uri, $payload);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $error = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $error['code'] ?? null);
    }

    public function testOperatorGetsForbiddenScopeOnAgentRegistrationEndpoint(): void
    {
        $client = $this->createOperatorClient();

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => '-----BEGIN PGP PUBLIC KEY BLOCK----- test -----END PGP PUBLIC KEY BLOCK-----',
            'openpgp_fingerprint' => 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
        ], [
            'HTTP_X_RETAIA_AGENT_ID' => '11111111-1111-4111-8111-111111111111',
            'HTTP_X_RETAIA_OPENPGP_FINGERPRINT' => 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'HTTP_X_RETAIA_SIGNATURE' => 'test-signature',
            'HTTP_X_RETAIA_SIGNATURE_TIMESTAMP' => '2026-03-19T12:00:00+00:00',
            'HTTP_X_RETAIA_SIGNATURE_NONCE' => 'test-nonce',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    public function testNonAdminUserGetsForbiddenActorOnAdminEndpoint(): void
    {
        $client = $this->createOperatorClient();

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/admin-confirm', [
            'email' => FixtureUsers::UNVERIFIED_EMAIL,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testOperatorGetsForbiddenActorOnDerivedUploadInit(): void
    {
        $client = $this->createOperatorClient();
        $this->seedDecisionPendingAsset();

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/derived/upload/init', [
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 1024,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    private function seedDecisionPendingAsset(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $asset = new Asset('11111111-1111-1111-1111-111111111111', 'VIDEO', 'authz.mov', AssetState::DECISION_PENDING);
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    private function createAdminClient(): KernelBrowser
    {
        return $this->loginClient(FixtureUsers::ADMIN_EMAIL, FixtureUsers::DEFAULT_PASSWORD);
    }

    private function createAgentClient(): KernelBrowser
    {
        $client = $this->loginClient(FixtureUsers::AGENT_EMAIL, FixtureUsers::DEFAULT_PASSWORD);
        $client->setServerParameter('HTTP_X_RETAIA_AGENT_ID', '11111111-1111-4111-8111-111111111111');
        $client->setServerParameter('HTTP_X_RETAIA_OPENPGP_FINGERPRINT', 'ABCD1234EF567890ABCD1234EF567890ABCD1234');
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE', 'test-signature');
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE_TIMESTAMP', '2026-03-19T12:00:00+00:00');
        $client->setServerParameter('HTTP_X_RETAIA_SIGNATURE_NONCE', 'test-nonce');

        return $client;
    }

    private function createOperatorClient(): KernelBrowser
    {
        return $this->loginClient(FixtureUsers::OPERATOR_EMAIL, FixtureUsers::DEFAULT_PASSWORD);
    }

    private function loginClient(string $email, string $password): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

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

        return $client;
    }

    private function requestJson(KernelBrowser $client, string $method, string $uri, array $payload = []): void
    {
        $normalizedMethod = strtoupper($method);
        if (in_array($normalizedMethod, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $client->jsonRequest($normalizedMethod, $uri, $payload);

            return;
        }

        $client->request($normalizedMethod, $uri);
    }

    /**
     * @return iterable<array{string, string, array<string, mixed>}>
     */
    public static function jobEndpointMatrixProvider(): iterable
    {
        yield ['GET', '/api/v1/jobs', []];
        yield ['POST', '/api/v1/jobs/job-authz-1/claim', []];
        yield ['POST', '/api/v1/jobs/job-authz-1/heartbeat', ['lock_token' => 't']];
        yield ['POST', '/api/v1/jobs/job-authz-1/submit', ['lock_token' => 't', 'job_type' => 'extract_facts', 'result_payload' => []]];
        yield ['POST', '/api/v1/jobs/job-authz-1/fail', ['lock_token' => 't', 'error_code' => 'GENERIC_ERROR']];
    }

    /**
     * @return iterable<array{string, string, array<string, mixed>}>
     */
    public static function opsEndpointMatrixProvider(): iterable
    {
        yield ['GET', '/api/v1/ops/ingest/diagnostics', []];
        yield ['GET', '/api/v1/ops/readiness', []];
        yield ['GET', '/api/v1/ops/locks', []];
        yield ['POST', '/api/v1/ops/locks/recover', []];
        yield ['GET', '/api/v1/ops/jobs/queue', []];
        yield ['GET', '/api/v1/ops/agents', []];
        yield ['GET', '/api/v1/ops/ingest/unmatched', []];
        yield ['POST', '/api/v1/ops/ingest/requeue', []];
    }
}
