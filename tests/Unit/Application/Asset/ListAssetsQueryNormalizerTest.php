<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\ListAssetsQueryNormalizer;
use PHPUnit\Framework\TestCase;

final class ListAssetsQueryNormalizerTest extends TestCase
{
    private ListAssetsQueryNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ListAssetsQueryNormalizer();
    }

    public function testNormalizeReturnsNormalizedPayload(): void
    {
        $result = $this->normalizer->normalize(
            [' ready ', 'READY', 'archived'],
            null,
            '2026-01-01T00:00:00Z',
            '2026-01-31T00:00:00Z',
            ['wedding'],
            'or',
            '4.3,50.8,4.45,50.92',
        );

        self::assertIsArray($result);
        self::assertSame(['READY', 'ARCHIVED'], $result['states']);
        self::assertSame('-created_at', $result['sort']);
        self::assertSame('OR', $result['tagsMode']);
        self::assertSame([
            'min_lon' => 4.3,
            'min_lat' => 50.8,
            'max_lon' => 4.45,
            'max_lat' => 50.92,
        ], $result['geoBbox']);
        self::assertSame('2026-01-01T00:00:00+00:00', $result['capturedAtFrom']?->format(DATE_ATOM));
        self::assertSame('2026-01-31T00:00:00+00:00', $result['capturedAtTo']?->format(DATE_ATOM));
    }

    public function testNormalizeRejectsInvalidStates(): void
    {
        self::assertNull($this->normalizer->normalize(['NOPE'], null, null, null, [], 'AND', null));
    }

    public function testNormalizeRejectsInvalidTagsMode(): void
    {
        self::assertNull($this->normalizer->normalize([], null, null, null, [], 'XOR', null));
    }

    public function testNormalizeRejectsInvalidSort(): void
    {
        self::assertNull($this->normalizer->normalize([], 'invalid-sort', null, null, [], 'AND', null));
    }

    public function testNormalizeRejectsInvalidDateRange(): void
    {
        self::assertNull($this->normalizer->normalize([], null, '2026-01-31T00:00:00Z', '2026-01-01T00:00:00Z', [], 'AND', null));
    }

    public function testNormalizeRejectsInvalidGeoBbox(): void
    {
        self::assertNull($this->normalizer->normalize([], null, null, null, [], 'AND', '4.5,50.8,4.3,50.9'));
    }
}
