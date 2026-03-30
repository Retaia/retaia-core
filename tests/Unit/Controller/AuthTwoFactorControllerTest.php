<?php

namespace App\Tests\Unit\Controller;

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
        $reflection = new \ReflectionClass(AuthCurrentSessionResolver::class);

        return $reflection->newInstanceWithoutConstructor();
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
