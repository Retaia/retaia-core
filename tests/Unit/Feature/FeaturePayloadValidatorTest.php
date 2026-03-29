<?php

namespace App\Tests\Unit\Feature;

use App\Feature\FeaturePayloadValidator;
use PHPUnit\Framework\TestCase;

final class FeaturePayloadValidatorTest extends TestCase
{
    public function testValidatesUnknownAndNonBooleanKeys(): void
    {
        $validator = new FeaturePayloadValidator();

        self::assertSame([
            'unknown_keys' => ['features.unknown'],
            'non_boolean_keys' => ['features.ai.suggest_tags'],
        ], $validator->validateFeaturePayload([
            'features.unknown' => true,
            'features.ai.suggest_tags' => 'yes',
        ], ['features.ai.suggest_tags']));
    }
}
