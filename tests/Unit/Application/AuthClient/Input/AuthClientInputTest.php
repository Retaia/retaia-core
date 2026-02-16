<?php

namespace App\Tests\Unit\Application\AuthClient\Input;

use App\Application\AuthClient\Input\DeviceCodeInput;
use App\Application\AuthClient\Input\MintClientTokenInput;
use App\Application\AuthClient\Input\StartDeviceFlowInput;
use PHPUnit\Framework\TestCase;

final class AuthClientInputTest extends TestCase
{
    public function testMintClientTokenInputValidation(): void
    {
        $valid = MintClientTokenInput::fromPayload([
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'secret',
        ]);
        self::assertTrue($valid->isValid());
        self::assertSame('agent-default', $valid->clientId());

        $invalid = MintClientTokenInput::fromPayload([]);
        self::assertFalse($invalid->isValid());
    }

    public function testStartDeviceFlowInputValidation(): void
    {
        $valid = StartDeviceFlowInput::fromPayload(['client_kind' => 'AGENT']);
        self::assertTrue($valid->isValid());
        self::assertSame('AGENT', $valid->clientKind());

        $invalid = StartDeviceFlowInput::fromPayload([]);
        self::assertFalse($invalid->isValid());
    }

    public function testDeviceCodeInputValidation(): void
    {
        $valid = DeviceCodeInput::fromPayload(['device_code' => 'dc_1']);
        self::assertTrue($valid->isValid());
        self::assertSame('dc_1', $valid->deviceCode());

        $invalid = DeviceCodeInput::fromPayload([]);
        self::assertFalse($invalid->isValid());
    }
}
