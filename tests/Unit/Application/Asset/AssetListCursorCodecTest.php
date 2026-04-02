<?php

namespace App\Tests\Unit\Application\Asset;

use App\Application\Asset\AssetListCursorCodec;
use PHPUnit\Framework\TestCase;

final class AssetListCursorCodecTest extends TestCase
{
    private AssetListCursorCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AssetListCursorCodec();
    }

    public function testEncodeAndDecodeRoundTrip(): void
    {
        $hash = $this->codec->contextHash(
            ['READY'],
            'video',
            'rush',
            '-created_at',
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-31T00:00:00Z'),
            10,
            ['wedding'],
            'OR',
            true,
            'BE',
            'Brussels',
            [
                'min_lon' => 4.3,
                'min_lat' => 50.8,
                'max_lon' => 4.45,
                'max_lat' => 50.92,
            ],
        );

        $cursor = $this->codec->encode(12, $hash);

        self::assertSame(12, $this->codec->decodeOffset($cursor, $hash));
    }

    public function testContextHashNormalizesMediaTypeAndQuery(): void
    {
        $left = $this->codec->contextHash([], ' video ', ' rush ', '-created_at', null, null, 10, [], 'AND', null, null, null, null);
        $right = $this->codec->contextHash([], 'VIDEO', 'rush', '-created_at', null, null, 10, [], 'AND', null, null, null, null);

        self::assertSame($left, $right);
    }

    public function testDecodeOffsetReturnsZeroForEmptyCursor(): void
    {
        self::assertSame(0, $this->codec->decodeOffset(null, 'hash'));
        self::assertSame(0, $this->codec->decodeOffset('   ', 'hash'));
    }

    public function testDecodeOffsetRejectsMismatchedContext(): void
    {
        $cursor = $this->codec->encode(2, 'wrong');

        self::assertNull($this->codec->decodeOffset($cursor, 'expected'));
    }

    public function testDecodeOffsetRejectsInvalidPayload(): void
    {
        self::assertNull($this->codec->decodeOffset('not-base64', 'hash'));

        $cursor = rtrim(strtr(base64_encode(json_encode(['offset' => -1, 'context_hash' => 'hash'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        self::assertNull($this->codec->decodeOffset($cursor, 'hash'));
    }
}
