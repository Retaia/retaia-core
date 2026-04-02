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
            'errors' => new AuthApiErrorResponder($this->translator()),
            'currentSessionResolver' => $this->currentSessionResolver(),
        ]);

        self::assertSame(401, $controller->setup(Request::create('/api/v1/auth/2fa/setup', 'POST'))->getStatusCode());
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

    private function translator(): TranslatorInterface
    {
        return $this->createTranslatorStub();
    }
}
