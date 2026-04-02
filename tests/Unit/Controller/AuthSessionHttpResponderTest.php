<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Application\Auth\AuthMeEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthSessionHttpResponder;
use PHPUnit\Framework\TestCase;

final class AuthSessionHttpResponderTest extends TestCase
{
    use TranslatorStubTrait;

    public function testMeMapsAuthenticatedPayload(): void
    {
        $responder = new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->me(new AuthMeEndpointResult(
            AuthMeEndpointResult::STATUS_SUCCESS,
            'user-1',
            'john@example.test',
            ['ROLE_USER'],
            'John',
            true,
            true,
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'id' => 'user-1',
            'uuid' => 'user-1',
            'email' => 'john@example.test',
            'display_name' => 'John',
            'email_verified' => true,
            'roles' => ['ROLE_USER'],
            'mfa_enabled' => true,
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRevokeMySessionReturnsConflictForCurrentSession(): void
    {
        $responder = new AuthSessionHttpResponder(new AuthApiErrorResponder($this->translatorStub()));
        $response = $responder->revokeMySession('CURRENT_SESSION');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'STATE_CONFLICT',
            'message' => 'auth.error.state_conflict',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

}
