<?php

namespace App\Tests\Unit\Application\Derived;

use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Derived\CheckDerivedAssetExistsHandler;
use App\Application\Derived\CompleteDerivedUploadHandler;
use App\Application\Derived\DerivedEndpointResult;
use App\Application\Derived\DerivedEndpointsHandler;
use App\Application\Derived\GetDerivedByKindHandler;
use App\Application\Derived\InitDerivedUploadHandler;
use App\Application\Derived\ListDerivedFilesHandler;
use App\Application\Derived\Port\DerivedGateway;
use App\Application\Derived\UploadDerivedPartHandler;
use App\Tests\Support\InMemoryDerivedGateway;
use PHPUnit\Framework\TestCase;

final class DerivedEndpointsHandlerTest extends TestCase
{
    public function testInitUploadReturnsForbiddenActor(): void
    {
        $handler = $this->buildHandler(false, new InMemoryDerivedGateway());
        $result = $handler->initUpload('a1', []);

        self::assertSame(DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testInitUploadReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $handler = $this->buildHandler(true, new InMemoryDerivedGateway());
        $result = $handler->initUpload('a1', []);

        self::assertSame(DerivedEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testUploadPartReturnsStateConflict(): void
    {
        $gateway = new InMemoryDerivedGateway();
        $gateway->addPartSuccess = false;

        $handler = $this->buildHandler(true, $gateway);
        $result = $handler->uploadPart('a1', ['upload_id' => 'up1', 'part_number' => 1]);

        self::assertSame(DerivedEndpointResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testListDerivedReturnsNotFoundWhenAssetUnknown(): void
    {
        $gateway = new InMemoryDerivedGateway();
        $gateway->assetExists = false;

        $handler = $this->buildHandler(true, $gateway);
        $result = $handler->listDerived('missing');

        self::assertSame(DerivedEndpointResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testGetByKindReturnsSuccess(): void
    {
        $gateway = new InMemoryDerivedGateway();
        $gateway->derivedByKind = ['id' => 'd1', 'kind' => 'proxy_video'];

        $handler = $this->buildHandler(true, $gateway);
        $result = $handler->getByKind('a1', 'proxy_video');

        self::assertSame(DerivedEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('d1', $result->payload()['id'] ?? null);
    }

    private function buildHandler(bool $isAgent, DerivedGateway $gateway): DerivedEndpointsHandler
    {
        $agentActorGateway = new class ($isAgent) implements AgentActorGateway {
            public function __construct(
                private bool $isAgent,
            ) {
            }

            public function isAgent(): bool
            {
                return $this->isAgent;
            }
        };

        return new DerivedEndpointsHandler(
            new ResolveAgentActorHandler($agentActorGateway),
            new CheckDerivedAssetExistsHandler($gateway),
            new InitDerivedUploadHandler($gateway),
            new UploadDerivedPartHandler($gateway),
            new CompleteDerivedUploadHandler($gateway),
            new ListDerivedFilesHandler($gateway),
            new GetDerivedByKindHandler($gateway),
        );
    }
}
