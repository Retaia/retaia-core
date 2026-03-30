<?php

namespace App\Tests\Unit\Controller;

use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\AuthClient\CompleteDeviceApprovalHandler;
use App\Controller\DeviceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testInfoReturnsApprovalPayload(): void
    {
        $controller = $this->controller(DeviceController::class, [
            'resolveAuthenticatedUserHandler' => new ResolveAuthenticatedUserHandler(new class implements AuthenticatedUserGateway {
                public function currentUser(): ?array
                {
                    return null;
                }
            }),
            'completeDeviceApprovalHandler' => (new \ReflectionClass(CompleteDeviceApprovalHandler::class))->newInstanceWithoutConstructor(),
            'translator' => $this->translator(),
            'twoFactorChallengeRateLimiter' => (new \ReflectionClass(RateLimiterFactory::class))->newInstanceWithoutConstructor(),
        ]);

        $response = $controller->info(Request::create('/device?user_code=abc', 'GET'));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('abc', $payload['user_code'] ?? null);
    }

    public function testApproveReturnsUnauthorizedWhenNoAuthenticatedUserExists(): void
    {
        $controller = $this->controller(DeviceController::class, [
            'resolveAuthenticatedUserHandler' => new ResolveAuthenticatedUserHandler(new class implements AuthenticatedUserGateway {
                public function currentUser(): ?array
                {
                    return null;
                }
            }),
            'completeDeviceApprovalHandler' => (new \ReflectionClass(CompleteDeviceApprovalHandler::class))->newInstanceWithoutConstructor(),
            'translator' => $this->translator(),
            'twoFactorChallengeRateLimiter' => (new \ReflectionClass(RateLimiterFactory::class))->newInstanceWithoutConstructor(),
        ]);

        self::assertSame(401, $controller->approve(Request::create('/device', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
