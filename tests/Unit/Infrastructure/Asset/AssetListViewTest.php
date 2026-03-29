<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Asset\AssetState;
use App\Derived\DerivedFileRepositoryInterface;
use App\Entity\Asset;
use App\Infrastructure\Asset\AssetDerivedViewProjector;
use App\Infrastructure\Asset\AssetListView;
use PHPUnit\Framework\TestCase;

final class AssetListViewTest extends TestCase
{
    public function testFilterAndSortAppliesStateTagPreviewLocationGeoAndSort(): void
    {
        $repository = $this->createMock(DerivedFileRepositoryInterface::class);
        $repository->method('listByAsset')->willReturnCallback(static function (string $assetUuid): array {
            if ($assetUuid === 'a-2') {
                return [];
            }

            return [];
        });

        $view = new AssetListView(new AssetDerivedViewProjector($repository));

        $matching = new Asset('a-1', 'VIDEO', 'Alpha.mp4', AssetState::READY, ['wedding', 'rush'], null, [
            'captured_at' => '2026-01-10T00:00:00Z',
            'location_country' => 'BE',
            'location_city' => 'Brussels',
            'gps_longitude' => 4.4,
            'gps_latitude' => 50.85,
            'derived' => ['preview_video_url' => 'https://cdn/preview.mp4'],
        ]);
        $nonMatching = new Asset('a-2', 'VIDEO', 'Zulu.mp4', AssetState::DISCOVERED, ['other'], null, [
            'captured_at' => '2026-01-01T00:00:00Z',
            'location_country' => 'FR',
            'location_city' => 'Paris',
            'gps_longitude' => 2.35,
            'gps_latitude' => 48.85,
        ]);

        $result = $view->filterAndSort(
            [$nonMatching, $matching],
            ['READY'],
            new \DateTimeImmutable('2026-01-05T00:00:00Z'),
            new \DateTimeImmutable('2026-01-20T00:00:00Z'),
            ['wedding'],
            'AND',
            true,
            'BE',
            'Brussels',
            [
                'min_lon' => 4.3,
                'min_lat' => 50.8,
                'max_lon' => 4.5,
                'max_lat' => 50.9,
            ],
            'name',
        );

        self::assertSame(['a-1'], array_map(static fn (Asset $asset): string => $asset->getUuid(), $result));
    }
}
