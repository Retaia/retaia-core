<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AssetStateMachineApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testDecisionTransitionWorksFromDecisionPendingToKeep(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/decision', [
            'action' => 'KEEP',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECIDED_KEEP', $payload['state'] ?? null);
    }

    public function testDecisionTransitionReturns409WhenForbidden(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/22222222-2222-2222-2222-222222222222/decision', [
            'action' => 'KEEP',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testReopenFromArchivedTransitionsToDecisionPending(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/33333333-3333-3333-3333-333333333333/reopen');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECISION_PENDING', $payload['state'] ?? null);
    }

    public function testListAssetsFiltersByState(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?state=PROCESSED&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][0]['uuid'] ?? null);
    }

    private function createAuthenticatedClient(bool $seedAssets = false): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        if ($seedAssets) {
            $this->seedAssets();
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $client;
    }

    private function seedAssets(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $asset1 = new Asset('11111111-1111-1111-1111-111111111111', 'VIDEO', 'rush-001.mov', AssetState::DECISION_PENDING);
        $asset1->setTags(['wedding']);
        $asset1->setNotes('first review');
        $asset1->setFields(['camera' => 'a7s']);

        $asset2 = new Asset('22222222-2222-2222-2222-222222222222', 'AUDIO', 'voice-001.wav', AssetState::PROCESSED);
        $asset3 = new Asset('33333333-3333-3333-3333-333333333333', 'PHOTO', 'archive-001.jpg', AssetState::ARCHIVED);

        $entityManager->persist($asset1);
        $entityManager->persist($asset2);
        $entityManager->persist($asset3);
        $entityManager->flush();
    }
}
