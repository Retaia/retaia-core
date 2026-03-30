<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\MyFeaturesResult;
use App\Application\Auth\PatchMyFeaturesEndpointResult;
use App\Application\Auth\ResetPasswordEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthSelfServiceHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthSelfServiceHttpResponderTest extends TestCase
{
    public function testPatchMeFeaturesKeepsValidationDetailsShape(): void
    {
        $responder = new AuthSelfServiceHttpResponder(new AuthApiErrorResponder($this->translator()));
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

    public function testResetUsesTranslatedFallbackViolation(): void
    {
        $responder = new AuthSelfServiceHttpResponder(new AuthApiErrorResponder($this->translator()));
        $response = $responder->reset(new ResetPasswordEndpointResult(ResetPasswordEndpointResult::STATUS_VALIDATION_FAILED));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.token_new_password_required',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTwoFactorEnableReturnsRecoveryCodesOnSuccess(): void
    {
        $responder = new AuthSelfServiceHttpResponder(new AuthApiErrorResponder($this->translator()));
        $response = $responder->twoFactorEnable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ENABLED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => true,
            'recovery_codes' => ['one', 'two'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMeFeaturesBuildsCanonicalPayload(): void
    {
        $responder = new AuthSelfServiceHttpResponder(new AuthApiErrorResponder($this->translator()));
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

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
