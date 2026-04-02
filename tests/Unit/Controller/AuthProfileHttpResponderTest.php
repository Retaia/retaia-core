<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Application\Auth\GetMyFeaturesEndpointResult;
use App\Application\Auth\MyFeaturesResult;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthProfileHttpResponder;
use PHPUnit\Framework\TestCase;

final class AuthProfileHttpResponderTest extends TestCase
{
    use TranslatorStubTrait;

    public function testMeFeaturesBuildsCanonicalPayload(): void
    {
        $responder = new AuthProfileHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $features = new MyFeaturesResult(['a' => true], ['a' => true], ['a' => ['source' => 'user']], [['key' => 'a']], ['a']);
        $response = $responder->meFeatures(new PatchMyFeaturesEndpointResult(PatchMyFeaturesEndpointResult::STATUS_UPDATED, null, $features));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'user_feature_enabled' => ['a' => true],
            'effective_feature_enabled' => ['a' => true],
            'effective_feature_explanations' => ['a' => ['source' => 'user']],
            'feature_governance' => [['key' => 'a']],
            'core_v1_global_features' => ['a'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPatchMeFeaturesKeepsValidationDetailsShape(): void
    {
        $responder = new AuthProfileHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->meFeatures(new PatchMyFeaturesEndpointResult(
            PatchMyFeaturesEndpointResult::STATUS_VALIDATION_FAILED,
            ['unknown_keys' => ['x'], 'non_boolean_keys' => ['y']],
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.invalid_user_feature_payload',
            'details' => ['unknown_keys' => ['x'], 'non_boolean_keys' => ['y']],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMeFeaturesRequiresAuthentication(): void
    {
        $responder = new AuthProfileHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->meFeatures(new GetMyFeaturesEndpointResult(GetMyFeaturesEndpointResult::STATUS_UNAUTHORIZED));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([
            'code' => 'UNAUTHORIZED',
            'message' => 'auth.error.authentication_required',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

}
