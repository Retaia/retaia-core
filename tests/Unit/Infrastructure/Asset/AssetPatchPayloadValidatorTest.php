<?php

namespace App\Tests\Unit\Infrastructure\Asset;

use App\Infrastructure\Asset\AssetPatchPayloadValidator;
use PHPUnit\Framework\TestCase;

final class AssetPatchPayloadValidatorTest extends TestCase
{
    public function testApplyMutableMetadataFieldsValidatesAndMutatesKnownFields(): void
    {
        $validator = new AssetPatchPayloadValidator();
        $fields = [];

        self::assertTrue($validator->applyMutableMetadataFields($fields, [
            'captured_at' => '2026-01-01T12:00:00Z',
            'gps_latitude' => 48.5,
            'gps_longitude' => 2.1,
            'location_city' => 'Paris',
            'processing_profile' => 'video_standard',
        ]));

        self::assertSame([
            'captured_at' => '2026-01-01T12:00:00Z',
            'gps_latitude' => 48.5,
            'gps_longitude' => 2.1,
            'location_city' => 'Paris',
            'processing_profile' => 'video_standard',
        ], $fields);
    }

    public function testApplyMutableMetadataFieldsRejectsInvalidPayloads(): void
    {
        $validator = new AssetPatchPayloadValidator();
        $fields = [];

        self::assertFalse($validator->applyMutableMetadataFields($fields, ['captured_at' => 'not-a-date']));
        self::assertFalse($validator->applyMutableMetadataFields($fields, ['gps_latitude' => 120]));
        self::assertFalse($validator->applyMutableMetadataFields($fields, ['location_city' => 42]));
        self::assertFalse($validator->applyMutableMetadataFields($fields, ['processing_profile' => 'unknown']));
    }
}
