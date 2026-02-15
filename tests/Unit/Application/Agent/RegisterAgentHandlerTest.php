<?php

namespace App\Tests\Unit\Application\Agent;

use App\Application\Agent\RegisterAgentHandler;
use App\Application\Agent\RegisterAgentResult;
use App\Domain\AppPolicy\FeatureFlagsContractPolicy;
use PHPUnit\Framework\TestCase;

final class RegisterAgentHandlerTest extends TestCase
{
    public function testHandleReturnsRegisteredPolicyUsingLatestContractByDefault(): void
    {
        $handler = new RegisterAgentHandler(
            new FeatureFlagsContractPolicy(),
            false,
            true,
            false,
            '1.0.0',
            ['1.0.0', '0.9.0']
        );

        $result = $handler->handle('user-1', 'ffmpeg-worker', '');

        self::assertSame(RegisterAgentResult::STATUS_REGISTERED, $result->status());
        self::assertSame(['1.0.0', '0.9.0'], $result->acceptedFeatureFlagsContractVersions());
        self::assertSame('user-1:ffmpeg-worker', $result->payload()['agent_id'] ?? null);
        self::assertSame('1.0.0', $result->payload()['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('STRICT', $result->payload()['server_policy']['feature_flags_compatibility_mode'] ?? null);
    }

    public function testHandleReturnsCompatPolicyForAcceptedLegacyContract(): void
    {
        $handler = new RegisterAgentHandler(
            new FeatureFlagsContractPolicy(),
            false,
            false,
            false,
            '1.0.0',
            ['0.9.0']
        );

        $result = $handler->handle('user-1', 'ffmpeg-worker', '0.9.0');

        self::assertSame(RegisterAgentResult::STATUS_REGISTERED, $result->status());
        self::assertSame('0.9.0', $result->payload()['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('COMPAT', $result->payload()['server_policy']['feature_flags_compatibility_mode'] ?? null);
        self::assertSame(['0.9.0', '1.0.0'], $result->acceptedFeatureFlagsContractVersions());
    }

    public function testHandleReturnsUnsupportedWhenContractVersionUnknown(): void
    {
        $handler = new RegisterAgentHandler(
            new FeatureFlagsContractPolicy(),
            false,
            false,
            false,
            '1.0.0',
            ['0.9.0']
        );

        $result = $handler->handle('user-1', 'ffmpeg-worker', '2.0.0');

        self::assertSame(RegisterAgentResult::STATUS_UNSUPPORTED_CONTRACT_VERSION, $result->status());
        self::assertNull($result->payload());
        self::assertSame(['0.9.0', '1.0.0'], $result->acceptedFeatureFlagsContractVersions());
    }
}
