<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AuthController;
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
            'translator' => $this->translator(),
        ]);

        self::assertSame(401, $controller->logout(Request::create('/api/v1/auth/logout', 'POST'))->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
