<?php

namespace App\Tests\Unit\Application\Agent;

use App\Application\Agent\RegisterAgentEndpointHandler;
use App\Application\Agent\RegisterAgentEndpointResult;
use App\Application\Agent\RegisterAgentResult;
use App\Application\Agent\RegisterAgentUseCase;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\Auth\ResolveAuthenticatedUserUseCase;
use PHPUnit\Framework\TestCase;

final class RegisterAgentEndpointHandlerTest extends TestCase
{
    public function testHandleReturnsValidationFailedWhenPayloadInvalid(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::never())->method('handle');

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::never())->method('handle');

        $result = (new RegisterAgentEndpointHandler($register, $resolver))->handle([
            'agent_name' => 'worker',
            'agent_version' => '',
            'capabilities' => ['extract_facts'],
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }

    public function testHandleUsesAuthenticatedActorAndReturnsRegisteredPayload(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::once())->method('handle')->with('u1', 'ffmpeg', '1.0.0')->willReturn(
            new RegisterAgentResult(RegisterAgentResult::STATUS_REGISTERED, ['1.0.0'], ['agent_id' => 'u1:ffmpeg'])
        );

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::once())->method('handle')->willReturn(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_AUTHENTICATED, 'u1', 'u1@retaia.local', ['ROLE_USER'])
        );

        $result = (new RegisterAgentEndpointHandler($register, $resolver))->handle([
            'agent_name' => 'ffmpeg',
            'agent_version' => '2.1.0',
            'capabilities' => ['extract_facts'],
            'client_feature_flags_contract_version' => '1.0.0',
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_REGISTERED, $result->status());
        self::assertSame(['agent_id' => 'u1:ffmpeg'], $result->payload());
    }

    public function testHandleReturnsUnsupportedContractVersionWhenRegisterHandlerRejects(): void
    {
        $register = $this->createMock(RegisterAgentUseCase::class);
        $register->expects(self::once())->method('handle')->with('unknown', 'ffmpeg', '2.0.0')->willReturn(
            new RegisterAgentResult(RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, ['1.0.0', '0.9.0'])
        );

        $resolver = $this->createMock(ResolveAuthenticatedUserUseCase::class);
        $resolver->expects(self::once())->method('handle')->willReturn(
            new ResolveAuthenticatedUserResult(ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED)
        );

        $result = (new RegisterAgentEndpointHandler($register, $resolver))->handle([
            'agent_name' => 'ffmpeg',
            'agent_version' => '2.1.0',
            'capabilities' => ['extract_facts'],
            'client_feature_flags_contract_version' => '2.0.0',
        ]);

        self::assertSame(RegisterAgentEndpointResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, $result->status());
        self::assertSame(['1.0.0', '0.9.0'], $result->acceptedFeatureFlagsContractVersions());
    }
}
