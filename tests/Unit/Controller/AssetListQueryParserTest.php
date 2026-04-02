<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AssetListQueryParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetListQueryParserTest extends TestCase
{
    public function testParseNormalizesQueryParameters(): void
    {
        $parser = new AssetListQueryParser();
        $request = Request::create('/api/v1/assets', 'GET', [
            'state' => 'processed, archived ,processed',
            'media_type' => 'video',
            'q' => 'demo',
            'sort' => 'name',
            'captured_at_from' => '2026-01-01T00:00:00Z',
            'captured_at_to' => '2026-01-31T23:59:59Z',
            'limit' => '0',
            'cursor' => 'cursor-1',
            'tags' => 'Wedding, PARTY ,wedding',
            'tags_mode' => 'OR',
            'has_preview' => 'yes',
            'location_country' => ' BE ',
            'location_city' => ' Brussels ',
            'geo_bbox' => ' 4.30,50.80,4.45,50.92 ',
        ]);

        self::assertSame([
            'states' => ['PROCESSED', 'ARCHIVED'],
            'mediaType' => 'video',
            'query' => 'demo',
            'sort' => 'name',
            'capturedAtFrom' => '2026-01-01T00:00:00Z',
            'capturedAtTo' => '2026-01-31T23:59:59Z',
            'limit' => 1,
            'cursor' => 'cursor-1',
            'tags' => ['wedding', 'party', 'wedding'],
            'tagsMode' => 'OR',
            'hasPreview' => true,
            'locationCountry' => 'BE',
            'locationCity' => 'Brussels',
            'geoBbox' => '4.30,50.80,4.45,50.92',
        ], $parser->parse($request));
    }
}
