<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Entity\User;
use App\Entity\WebAuthnDevice;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineTimestampableEntitiesTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testAssetUpdatedAtIsAdvancedOnFlushByTimestampable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $createdAt = new \DateTimeImmutable('-2 hours');
        $updatedAt = new \DateTimeImmutable('-1 hour');
        $asset = new Asset(
            uuid: 'timestamp-asset-1',
            mediaType: 'VIDEO',
            filename: 'timestamp.mov',
            state: AssetState::DISCOVERED,
            tags: [],
            notes: null,
            fields: ['paths' => ['storage_id' => 'nas-main', 'original_relative' => 'INBOX/timestamp.mov', 'sidecars_relative' => []]],
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        $entityManager->persist($asset);
        $entityManager->flush();

        $asset->setNotes('updated-by-listener');
        $entityManager->flush();

        self::assertGreaterThan($updatedAt->getTimestamp(), $asset->getUpdatedAt()->getTimestamp());
    }

    public function testWebAuthnDeviceCreatedAtIsFilledOnCreateByTimestampable(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@retaia.local']);
        self::assertInstanceOf(User::class, $user);

        $device = new WebAuthnDevice(
            id: '11111111-2222-3333-4444-555555555555',
            userId: $user->getId(),
            credentialId: 'credential-1',
            deviceLabel: 'MacBook',
            webauthnFingerprint: str_repeat('a', 64),
        );

        self::assertNull($device->getCreatedAt());

        $entityManager->persist($device);
        $entityManager->flush();

        self::assertInstanceOf(\DateTimeImmutable::class, $device->getCreatedAt());
    }
}
