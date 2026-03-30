<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\IdempotencyService;
use App\Application\Asset\AssetEndpointsHandler;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Controller\Api\AssetController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testPatchReturnsForbiddenForAgentActor(): void
    {
        $handlerReflection = new \ReflectionClass(AssetEndpointsHandler::class);
        $handler = $handlerReflection->newInstanceWithoutConstructor();
        $property = $handlerReflection->getProperty('resolveAgentActorHandler');
        $property->setValue($handler, new ResolveAgentActorHandler(new class implements AgentActorGateway {
            public function isAgent(): bool
            {
                return true;
            }
        }));

        $controller = $this->controller(AssetController::class, [
            'assetEndpointsHandler' => $handler,
            'translator' => $this->translator(),
            'idempotency' => (new \ReflectionClass(IdempotencyService::class))->newInstanceWithoutConstructor(),
            'assetPreconditions' => (new \ReflectionClass(AssetRequestPreconditionService::class))->newInstanceWithoutConstructor(),
        ]);

        self::assertSame(403, $controller->patch('asset-1', Request::create('/api/v1/assets/asset-1', 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
