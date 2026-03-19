<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\SignedAgentRequestValidator;
use App\Application\Auth\Port\AgentActorGateway;
use App\Application\Auth\ResolveAgentActorHandler;
use App\Application\Derived\CheckDerivedAssetExistsHandler;
use App\Application\Derived\CompleteDerivedUploadHandler;
use App\Application\Derived\DerivedEndpointsHandler;
use App\Application\Derived\GetDerivedByKindHandler;
use App\Application\Derived\InitDerivedUploadHandler;
use App\Application\Derived\ListDerivedFilesHandler;
use App\Application\Derived\Port\DerivedGateway;
use App\Application\Derived\UploadDerivedPartHandler;
use App\Controller\Api\DerivedController;
use App\Entity\Asset;
use App\Tests\Support\InMemoryDerivedGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DerivedControllerTest extends TestCase
{
    public function testInitUploadForbiddenNotFoundAndValidation(): void
    {
        $forbiddenGateway = new InMemoryDerivedGateway();
        $controller = $this->controller(false, $forbiddenGateway);
        self::assertSame(Response::HTTP_FORBIDDEN, $controller->initUpload('a1', Request::create('/x', 'POST'))->getStatusCode());

        $notFoundGateway = new InMemoryDerivedGateway();
        $notFoundGateway->assetExists = false;
        $controller = $this->controller(true, $notFoundGateway);
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->initUpload('a2', $this->signedJsonRequest('{}'))->getStatusCode());

        $validationGateway = new InMemoryDerivedGateway();
        $controller = $this->controller(true, $validationGateway);
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->initUpload('a3', $this->signedJsonRequest('{}'))->getStatusCode()
        );
    }

    public function testUploadPartAndCompleteUploadValidationAndConflictBranches(): void
    {
        $gateway = new InMemoryDerivedGateway();
        $gateway->addPartSuccess = false;
        $gateway->completeResult = null;
        $controller = $this->controller(true, $gateway);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->uploadPart('a4', $this->signedJsonRequest('{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->uploadPart('a4', $this->signedJsonRequest('{"upload_id":"up","part_number":1}'))->getStatusCode()
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->completeUpload('a4', $this->signedJsonRequest('{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->completeUpload('a4', $this->signedJsonRequest('{"upload_id":"up","total_parts":1}'))->getStatusCode()
        );
    }

    public function testListDerivedAndGetByKindBranches(): void
    {
        $gateway = new InMemoryDerivedGateway();
        $gateway->assetExistsSequence = [false, false, true, true];
        $gateway->listItems = [];
        $gateway->derivedByKind = null;
        $controller = $this->controller(true, $gateway);

        self::assertSame(Response::HTTP_NOT_FOUND, $controller->listDerived('a1')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->getByKind('a1', 'proxy')->getStatusCode());

        self::assertSame(Response::HTTP_OK, $controller->listDerived('a2')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->getByKind('a2', 'proxy')->getStatusCode());
    }

    private function controller(bool $isAgent, DerivedGateway $gateway): DerivedController
    {
        $agentGateway = new class ($isAgent) implements AgentActorGateway {
            public function __construct(
                private bool $isAgent,
            ) {
            }

            public function isAgent(): bool
            {
                return $this->isAgent;
            }
        };

        return new DerivedController(
            new DerivedEndpointsHandler(
                new ResolveAgentActorHandler($agentGateway),
                new CheckDerivedAssetExistsHandler($gateway),
                new InitDerivedUploadHandler($gateway),
                new UploadDerivedPartHandler($gateway),
                new CompleteDerivedUploadHandler($gateway),
                new ListDerivedFilesHandler($gateway),
                new GetDerivedByKindHandler($gateway),
            ),
            $this->translator(),
            new AssetRequestPreconditionService(new class implements \App\Asset\Repository\AssetRepositoryInterface {
                public function findByUuid(string $uuid): ?Asset
                {
                    return null;
                }

                public function listAssets(?string $state, ?string $mediaType, ?string $query, int $limit): array
                {
                    return [];
                }

                public function save(Asset $asset): void
                {
                }
            }),
            new SignedAgentRequestValidator(),
        );
    }

    private function signedJsonRequest(string $content): Request
    {
        $request = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $content);
        $request->headers->set('X-Retaia-Agent-Id', '11111111-1111-4111-8111-111111111111');
        $request->headers->set('X-Retaia-OpenPGP-Fingerprint', 'ABCD1234EF567890ABCD1234EF567890ABCD1234');
        $request->headers->set('X-Retaia-Signature', 'test-signature');
        $request->headers->set('X-Retaia-Signature-Timestamp', '2026-03-19T12:00:00+00:00');
        $request->headers->set('X-Retaia-Signature-Nonce', 'test-nonce');

        return $request;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

}
