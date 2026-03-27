<?php

namespace App\Tests\Unit\Application\Agent;

use App\Api\Service\AgentSignature\AgentPublicKeyStore;
use App\Api\Service\AgentRuntimeStore;
use App\Application\Agent\RegisterAgentEndpointHandler;
use App\Application\Agent\RegisterAgentEndpointResult;
use App\Application\Agent\RegisterAgentResult;
use App\Application\Agent\RegisterAgentUseCase;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RegisterAgentEndpointHandlerTest extends TestCase
{
    private const ARMORED_PUBLIC_KEY = "-----BEGIN PGP PUBLIC KEY BLOCK-----\nVersion: Test\n\nabc\n-----END PGP PUBLIC KEY BLOCK-----";

    public function testHandleReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'worker',
            'agent_version' => '',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'fingerprint',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedWhenFingerprintIsMalformed(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'not-a-fingerprint',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedWhenPublicKeyIsNotAsciiArmored(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => 'public-key',
            'openpgp_fingerprint' => 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedWhenCapabilitiesContainNonStringValue(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'fingerprint',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts', 42],
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleReturnsValidationFailedWhenClientContractVersionIsNotSemver(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'fingerprint',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
            'client_feature_flags_contract_version' => '1.0',
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleUsesAuthenticatedActorAndReturnsRegisteredPayload(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::once())->method('handle')->with('u1', '11111111-1111-4111-8111-111111111111', 'ffmpeg', '1.0.0')->willReturn(
            new RegisterAgentResult(RegisterAgentResult::STATUS_REGISTERED, ['1.0.0'], ['agent_id' => '11111111-1111-4111-8111-111111111111'])
        );

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::once())->method('handle')->willReturn(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED, 'u1', 'u1@retaia.local', ['ROLE_USER'])
        );
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $runtimeStore = $this->runtimeStore();
        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $runtimeStore))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'ffmpeg',
            'agent_version' => '2.1.0',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
            'client_feature_flags_contract_version' => '1.0.0',
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_REGISTERED, $result->status());
        self::assertSame('11111111-1111-4111-8111-111111111111', $result->payload()['agent_id'] ?? null);
        self::assertSame(['extract_facts'], $result->payload()['effective_capabilities'] ?? null);
        self::assertSame([], $result->payload()['capability_warnings'] ?? null);
        self::assertSame(
            self::ARMORED_PUBLIC_KEY,
            $keyStore->publicKeyFor('11111111-1111-4111-8111-111111111111', 'ABCD1234EF567890ABCD1234EF567890ABCD1234')
        );
        self::assertCount(1, $runtimeStore->list());
    }

    public function testHandleReturnsUnsupportedContractVersionWhenRegisterHandlerRejects(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::once())->method('handle')->with('unknown', '11111111-1111-4111-8111-111111111111', 'ffmpeg', '2.0.0')->willReturn(
            new RegisterAgentResult(RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, ['1.0.0', '0.9.0'])
        );

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::once())->method('handle')->willReturn(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED)
        );
        $keyStore = new AgentPublicKeyStore(new ArrayAdapter());

        $result = (new RegisterAgentEndpointHandler($register, $resolver, $keyStore, $this->runtimeStore()))->handle([
            'agent_id' => '11111111-1111-4111-8111-111111111111',
            'agent_name' => 'ffmpeg',
            'agent_version' => '2.1.0',
            'openpgp_public_key' => self::ARMORED_PUBLIC_KEY,
            'openpgp_fingerprint' => 'ABCD1234EF567890ABCD1234EF567890ABCD1234',
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
            'client_feature_flags_contract_version' => '2.0.0',
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, $result->status());
        self::assertSame(['1.0.0', '0.9.0'], $result->acceptedFeatureFlagsContractVersions());
    }

    private function runtimeStore(): AgentRuntimeStore
    {
        return new AgentRuntimeStore($this->connection());
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $connection->executeStatement("CREATE TABLE agent_runtime (agent_id VARCHAR(36) PRIMARY KEY NOT NULL, client_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, agent_version VARCHAR(64) NOT NULL, os_name VARCHAR(32) DEFAULT NULL, os_version VARCHAR(64) DEFAULT NULL, arch VARCHAR(32) DEFAULT NULL, effective_capabilities CLOB NOT NULL, capability_warnings CLOB NOT NULL, last_register_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, last_heartbeat_at DATETIME DEFAULT NULL, max_parallel_jobs INTEGER NOT NULL, feature_flags_contract_version VARCHAR(32) DEFAULT NULL, effective_feature_flags_contract_version VARCHAR(32) DEFAULT NULL, server_time_skew_seconds INTEGER DEFAULT NULL)");

        return $connection;
    }
}
