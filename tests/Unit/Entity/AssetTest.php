<?php

namespace App\Tests\Unit\Entity;

use App\Asset\AssetState;
use App\Entity\Asset;
use PHPUnit\Framework\TestCase;

final class AssetTest extends TestCase
{
    public function testMutatorsUpdateAssetValues(): void
    {
        $createdAt = new \DateTimeImmutable('-2 hours');
        $updatedAt = new \DateTimeImmutable('-1 hour');
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'video',
            filename: 'clip.mp4',
            state: AssetState::DISCOVERED,
            tags: ['news', 'news'],
            notes: null,
            fields: ['source' => 'ingest'],
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );

        self::assertSame('asset-1', $asset->getUuid());
        self::assertSame('video', $asset->getMediaType());
        self::assertSame('clip.mp4', $asset->getFilename());
        self::assertSame(AssetState::DISCOVERED, $asset->getState());
        self::assertSame($createdAt, $asset->getCreatedAt());
        self::assertSame($updatedAt, $asset->getUpdatedAt());

        $asset->setState(AssetState::READY);
        $asset->setTags(['sports', 'sports', 'highlights']);
        $asset->setNotes('Validated');
        $asset->setFields(['duration' => 90]);

        self::assertSame(AssetState::READY, $asset->getState());
        self::assertSame(['sports', 'highlights'], $asset->getTags());
        self::assertSame('Validated', $asset->getNotes());
        self::assertSame(['duration' => 90], $asset->getFields());
        self::assertGreaterThan($updatedAt->getTimestamp(), $asset->getUpdatedAt()->getTimestamp());
    }
}
