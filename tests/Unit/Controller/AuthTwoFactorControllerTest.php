<?php

namespace App\Tests\Unit\Controller;

use App\Auth\UserAccessJwtService;
use App\Auth\UserAccessTokenService;
use App\Auth\UserAuthSessionService;
use App\Auth\UserAuthSessionRepositoryInterface;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthCurrentSessionResolver;
use App\Controller\Api\AuthTwoFactorController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthTwoFactorControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testSetupRejectsMissingBearerToken(): void
    {
        $controller = $this->controller(AuthTwoFactorController::class, [
            'errors' => new AuthApiErrorResponder($this->createTranslatorStub()),
            'currentSessionResolver' => $this->currentSessionResolver(),
        ]);

        $response = $controller->setup(Request::create('/api/v1/auth/2fa/setup', 'POST'));

        self::assertSame(401, $response->getStatusCode());

        $contentType = $response->headers->get('Content-Type');
        if ($contentType !== null) {
            self::assertStringContainsStringIgnoringCase('application/json', $contentType);
        }

        $data = json_decode($response->getContent(), true);

        self::assertIsArray($data);
        self::assertNotEmpty($data);

        if (array_key_exists('error', $data)) {
            self::assertIsArray($data['error']);
            self::assertArrayHasKey('code', $data['error']);
            self::assertArrayHasKey('message', $data['error']);
            self::assertSame('UNAUTHORIZED', $data['error']['code']);
            self::assertSame('auth.error.authentication_required', $data['error']['message']);
        } else {
            self::assertArrayHasKey('message', $data);
            self::assertSame('auth.error.authentication_required', $data['message']);
        }
    }

    private function currentSessionResolver(): AuthCurrentSessionResolver
    {
        return new AuthCurrentSessionResolver(
            new UserAccessTokenService(
                new UserAuthSessionService($this->createMock(UserAuthSessionRepositoryInterface::class)),
                new UserAccessJwtService('test-secret', 3600),
            )
        );
    }

    private function createTranslatorStub(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
