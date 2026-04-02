<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Application\Auth\TwoFactorDisableEndpointResult;
use App\Application\Auth\TwoFactorEnableEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthTwoFactorHttpResponder;
use PHPUnit\Framework\TestCase;

final class AuthTwoFactorHttpResponderTest extends TestCase
{
    use TranslatorStubTrait;

    public function testEnableReturnsRecoveryCodesOnSuccess(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->enable(new TwoFactorEnableEndpointResult(
            TwoFactorEnableEndpointResult::STATUS_ENABLED,
            ['one', 'two'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => true,
            'recovery_codes' => ['one', 'two'],
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testDisableReturnsDisabledPayloadOnSuccess(): void
    {
        $responder = new AuthTwoFactorHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->disable(new TwoFactorDisableEndpointResult(
            TwoFactorDisableEndpointResult::STATUS_DISABLED,
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'mfa_enabled' => false,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

}
