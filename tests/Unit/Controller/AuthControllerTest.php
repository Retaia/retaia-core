<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthController;
use App\Controller\Api\AuthSessionHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testLoginThrowsLogicException(): void
    {
        $controller = $this->controller(AuthController::class, []);

        $this->expectException(\LogicException::class);
        $controller->login();
    }

    public function testLogoutRejectsMissingBearerToken(): void
    {
        $controller = $this->controller(AuthController::class, [
            'sessionResponder' => $this->sessionResponder(),
        ]);

        self::assertSame(401, $controller->logout(Request::create('/api/v1/auth/logout', 'POST'))->getStatusCode());
    }

    public function testRefreshRejectsMissingToken(): void
    {
        $controller = $this->controller(AuthController::class, [
            'sessionResponder' => $this->sessionResponder(),
        ]);

        $response = $controller->refresh(Request::create('/api/v1/auth/refresh', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'code' => 'VALIDATION_FAILED',
            'message' => 'auth.error.refresh_token_required',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTwoFactorSetupRejectsMissingBearerToken(): void
    {
        $controller = $this->controller(AuthController::class, [
            'errors' => $this->errorResponder(),
        ]);

        self::assertSame(401, $controller->twoFactorSetup(Request::create('/api/v1/auth/2fa/setup', 'POST'))->getStatusCode());
    }

    private function errorResponder(): AuthApiErrorResponder
    {
        return new AuthApiErrorResponder($this->translator());
    }

    private function sessionResponder(): AuthSessionHttpResponder
    {
        return new AuthSessionHttpResponder($this->errorResponder());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
