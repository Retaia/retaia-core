<?php

namespace App\Tests\Unit\Domain\AuthClient;

use App\Domain\AuthClient\DeviceFlowStatus;
use PHPUnit\Framework\TestCase;

final class DeviceFlowStatusTest extends TestCase
{
    public function testKnownStatuses(): void
    {
        self::assertSame(
            [DeviceFlowStatus::PENDING, DeviceFlowStatus::APPROVED, DeviceFlowStatus::DENIED, DeviceFlowStatus::EXPIRED],
            DeviceFlowStatus::all()
        );
    }

    public function testIsKnown(): void
    {
        self::assertTrue(DeviceFlowStatus::isKnown(DeviceFlowStatus::PENDING));
        self::assertTrue(DeviceFlowStatus::isKnown(DeviceFlowStatus::APPROVED));
        self::assertFalse(DeviceFlowStatus::isKnown('UNKNOWN'));
    }
}
