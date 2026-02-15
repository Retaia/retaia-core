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
use PHPUnit\Framework\TestCase;

final class DerivedEndpointsHandlerTest extends TestCase
{
    public function testInitUploadReturnsForbiddenActor(): void
    {
        $handler = $this->buildHandler(false, new InMemoryDerivedGatewayForEndpoints());
        $result = $handler->initUpload('a1', []);

        self::assertSame(DerivedEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testInitUploadReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $handler = $this->buildHandler(true, new InMemoryDerivedGatewayForEndpoints());
        $result = $handler->initUpload('a1', []);

        self::assertSame(DerivedEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testUploadPartReturnsStateConflict(): void
    {
        $gateway = new InMemoryDerivedGatewayForEndpoints();
        $gateway->addPartSuccess = false;

        $handler = $this->buildHandler(true, $gateway);
        $result = $handler->uploadPart('a1', ['upload_id' => 'up1', 'part_number' => 1]);

        self::assertSame(DerivedEndpointResult::STATUS_STATE_CONFLICT, $result->status());
    }

    public function testListDerivedReturnsNotFoundWhenAssetUnknown(): void
    {
        $gateway = new InMemoryDerivedGatewayForEndpoints();
        $gateway->assetExists = false;

        $handler = $this->buildHandler(true, $gateway);
        $result = $handler->listDerived('missing');

        self::assertSame(DerivedEndpointResult::STATUS_NOT_FOUND, $result->status());
    }

    public function testGetByKindReturnsSuccess(): void
    {
        $gateway = new InMemoryDerivedGatewayForEndpoints();
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

final class InMemoryDerivedGatewayForEndpoints implements DerivedGateway
{
    public bool $assetExists = true;
    /** @var array<string, mixed> */
    public array $initSession = ['upload_id' => 'u1'];
    public bool $addPartSuccess = true;
    /** @var array<string, mixed>|null */
    public ?array $completeResult = ['id' => 'd1'];
    /** @var array<int, array<string, mixed>> */
    public array $listItems = [];
    /** @var array<string, mixed>|null */
    public ?array $derivedByKind = ['id' => 'd1'];

    public function assetExists(string $assetUuid): bool
    {
        return $this->assetExists;
    }

    public function initUpload(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): array
    {
        return $this->initSession;
    }

    public function addUploadPart(string $uploadId, int $partNumber): bool
    {
        return $this->addPartSuccess;
    }

    public function completeUpload(string $assetUuid, string $uploadId, int $totalParts): ?array
    {
        return $this->completeResult;
    }

    public function listDerivedForAsset(string $assetUuid): array
    {
        return $this->listItems;
    }

    public function findDerivedByAssetAndKind(string $assetUuid, string $kind): ?array
    {
        return $this->derivedByKind;
    }
}
