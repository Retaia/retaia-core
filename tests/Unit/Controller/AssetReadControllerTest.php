<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Application\Asset\AssetEndpointsHandler;
use App\Application\Asset\GetAssetHandler;
use App\Application\Asset\ListAssetsHandler;
use App\Application\Asset\PatchAssetHandler;
use App\Application\Asset\Port\AssetPatchGateway;
use App\Application\Asset\Port\AssetReadGateway;
use App\Application\Asset\Port\AssetWorkflowGateway;
use App\Application\Asset\ReopenAssetHandler;
use App\Application\Asset\ReprocessAssetHandler;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\AssetHttpResponder;
use App\Controller\Api\AssetListQueryParser;
use App\Controller\Api\AssetReadController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AssetReadControllerTest extends TestCase
{
    use TranslatorStubTrait;

    public function testListReturnsValidationFailurePayload(): void
    {
        $gateway = $this->createStub(AssetReadGateway::class);
        $controller = new AssetReadController(
            $this->assetEndpointsHandler($gateway),
            $this->responder(),
            new AssetListQueryParser(),
        );

        $response = $controller->list(Request::create('/api/v1/assets', 'GET', ['sort' => 'oops']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    public function testGetOneAddsRevisionEtag(): void
    {
        $gateway = $this->createMock(AssetReadGateway::class);
        $gateway->method('getByUuid')->with('asset-1')->willReturn([
            'uuid' => 'asset-1',
            'updated_at' => '2026-04-02T12:00:00+00:00',
            'summary' => ['revision_etag' => '"etag-1"'],
        ]);
        $gateway->method('list')->willReturn(['items' => [], 'has_more' => false]);

        $controller = new AssetReadController(
            $this->assetEndpointsHandler($gateway),
            $this->responder(),
            new AssetListQueryParser(),
        );

        $response = $controller->getOne('asset-1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"etag-1"', $response->headers->get('ETag'));
    }

    private function assetEndpointsHandler(AssetReadGateway $readGateway): AssetEndpointsHandler
    {
        $patchGateway = $this->createStub(AssetPatchGateway::class);
        $patchGateway->method('patch')->willReturn(['status' => 'PATCHED', 'payload' => []]);

        $workflowGateway = $this->createStub(AssetWorkflowGateway::class);
        $workflowGateway->method('decide')->willReturn(['status' => 'DECIDED', 'payload' => []]);
        $workflowGateway->method('reopen')->willReturn(['status' => 'REOPENED', 'payload' => []]);
        $workflowGateway->method('reprocess')->willReturn(['status' => 'REPROCESSED', 'payload' => []]);

        return new AssetEndpointsHandler(
            new ListAssetsHandler($readGateway),
            new GetAssetHandler($readGateway),
            new PatchAssetHandler($patchGateway),
            new \App\Application\Asset\DecideAssetHandler($workflowGateway),
            new ReopenAssetHandler($workflowGateway),
            new ReprocessAssetHandler($workflowGateway),
            new ResolveAgentActorHandler(new class implements AgentActorGateway {
                public function isAgent(): bool
                {
                    return false;
                }
            }),
            new ResolveAuthenticatedUserHandler(new class implements AuthenticatedUserGateway {
                public function currentUser(): ?array
                {
                    return ['id' => 'user-1', 'email' => 'user@example.com', 'roles' => ['ROLE_USER']];
                }
            })
        );
    }

    private function responder(): AssetHttpResponder
    {
        return new AssetHttpResponder(
            $this->translatorStub(),
            new \App\Api\Service\AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class), $this->translatorStub())
        );
    }

}
