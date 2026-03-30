<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Controller\Api\OpsController;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testReadinessReturnsForbiddenWhenActorIsNotAdmin(): void
    {
        $gateway = new class implements AdminActorGateway {
            public function isAdmin(): bool
            {
                return false;
            }

            public function actorId(): ?string
            {
                return null;
            }
        };

        $controller = $this->controller(OpsController::class, [
            'resolveAdminActorHandler' => new ResolveAdminActorHandler($gateway),
            'translator' => $this->translator(),
        ]);

        self::assertSame(403, $controller->readiness()->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
