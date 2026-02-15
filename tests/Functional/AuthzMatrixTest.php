<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\FixtureUsers;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AuthzMatrixTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testAnonymousActorGetsUnauthorizedForMutatingAssetEndpoint(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/decision', ['action' => 'KEEP']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testAgentGetsForbiddenActorOnHumanAssetMutation(): void
    {
        $client = $this->createAgentClient();
        $this->seedDecisionPendingAsset();

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/decision', ['action' => 'KEEP']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testAdminGetsForbiddenScopeOnAgentJobEndpoint(): void
    {
        $client = $this->createAdminClient();

        $client->request('GET', '/api/v1/jobs');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    public function testAnonymousActorGetsUnauthorizedOnAgentJobEndpoint(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/jobs');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testOperatorGetsForbiddenScopeOnAgentRegistrationEndpoint(): void
    {
        $client = $this->createOperatorClient();

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'capabilities' => ['extract_facts'],
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

    public function testAgentGetsForbiddenActorOnHumanWorkflowBatchEndpoint(): void
    {
        $client = $this->createAgentClient();

        $client->jsonRequest('POST', '/api/v1/batches/moves/preview', [
            'uuids' => ['11111111-1111-1111-1111-111111111111'],
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
        return $this->loginClient(FixtureUsers::AGENT_EMAIL, FixtureUsers::DEFAULT_PASSWORD);
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
}
