<?php

namespace App\Tests\Unit\Controller;

use App\Tests\Support\TranslatorStubTrait;
use App\Api\Service\AssetRequestPreconditionService;
use App\Api\Service\AgentRuntimeRepository;
use App\Api\Service\AgentSignature\AgentPublicKeyRecord;
use App\Api\Service\AgentSignature\AgentPublicKeyRepository;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepository;
use App\Api\Service\AgentSignature\GpgCliAgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
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
use App\Tests\Support\AgentSigningTestHelper;
use App\Tests\Support\InMemoryDerivedGateway;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DerivedControllerTest extends TestCase
{
    use TranslatorStubTrait;

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

        $connection = $this->connection();
        $store = new AgentPublicKeyRepository($connection);
        $material = AgentSigningTestHelper::publicMaterial();
        $store->save(new AgentPublicKeyRecord($material['agent_id'], $material['fingerprint'], $material['public_key'], 1710000000));
        $validator = new SignedAgentRequestValidator(
            $store,
            new GpgCliAgentRequestSignatureVerifier(),
            new AgentSignatureNonceRepository($connection),
            new SignedAgentMessageCanonicalizer(),
            new AgentRuntimeRepository($connection),
            $this->translatorStub(),
        );

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
            $this->translatorStub(),
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
            }, $this->translatorStub()),
            $validator,
        );
    }

    private function signedJsonRequest(string $content): Request
    {
        $request = Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $content);
        $headers = AgentSigningTestHelper::signedHeadersForBody('POST', '/x', $content);
        foreach ($headers as $name => $value) {
            $headerName = str_replace('HTTP_', '', $name);
            $headerName = str_replace('_', '-', $headerName);
            $request->headers->set($headerName, $value);
        }

        return $request;
    }


    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");
        $connection->executeStatement('CREATE TABLE agent_public_key (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, openpgp_fingerprint VARCHAR(40) NOT NULL, openpgp_public_key CLOB NOT NULL, updated_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE TABLE agent_signature_nonce (nonce_key VARCHAR(64) PRIMARY KEY NOT NULL, agent_id VARCHAR(36) NOT NULL, expires_at INTEGER NOT NULL, consumed_at INTEGER NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_agent_signature_nonce_expires_at ON agent_signature_nonce (expires_at)');

        return $connection;
    }
}
