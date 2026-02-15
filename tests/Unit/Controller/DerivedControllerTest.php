<?php

namespace App\Tests\Unit\Controller;

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
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->initUpload('a2', Request::create('/x', 'POST'))->getStatusCode());

        $validationGateway = new InMemoryDerivedGateway();
        $controller = $this->controller(true, $validationGateway);
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->initUpload('a3', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
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
            $controller->uploadPart('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->uploadPart('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"upload_id":"up","part_number":1}'))->getStatusCode()
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->completeUpload('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->completeUpload('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"upload_id":"up","total_parts":1}'))->getStatusCode()
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
            $this->translator()
        );
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

}

final class InMemoryDerivedGateway implements DerivedGateway
{
    public bool $assetExists = true;
    /** @var array<int, bool> */
    public array $assetExistsSequence = [];
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
        if ($this->assetExistsSequence !== []) {
            /** @var bool $next */
            $next = array_shift($this->assetExistsSequence);

            return $next;
        }

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
