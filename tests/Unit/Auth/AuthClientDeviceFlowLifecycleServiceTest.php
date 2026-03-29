<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientDeviceFlowLifecycleService;
use App\Auth\AuthDeviceFlow;
use App\Auth\AuthDeviceFlowRepositoryInterface;
use App\Domain\AuthClient\DeviceFlowStatus;
use PHPUnit\Framework\TestCase;

final class AuthClientDeviceFlowLifecycleServiceTest extends TestCase
{
    public function testPollReturnsApprovedCredentialsAndDeletesFlow(): void
    {
        $repository = $this->createMock(AuthDeviceFlowRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByDeviceCode')
            ->with('device-1')
            ->willReturn(new AuthDeviceFlow(
                'device-1',
                'USER0001',
                'AGENT',
                DeviceFlowStatus::APPROVED,
                2_000_000_000,
                2_000_000_600,
                5,
                0,
                'client-1',
                'secret-1',
            ));
        $repository->expects(self::once())
            ->method('delete')
            ->with('device-1');
        $repository->expects(self::never())->method('save');

        $service = new AuthClientDeviceFlowLifecycleService($repository);
        $result = $service->pollDeviceFlow('device-1');

        self::assertSame(DeviceFlowStatus::APPROVED, $result['status'] ?? null);
        self::assertSame('client-1', $result['client_id'] ?? null);
        self::assertSame('secret-1', $result['secret_key'] ?? null);
    }

    public function testCancelReturnsDeniedForActiveFlow(): void
    {
        $repository = $this->createMock(AuthDeviceFlowRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByDeviceCode')
            ->with('device-2')
            ->willReturn(new AuthDeviceFlow(
                'device-2',
                'USER0002',
                'AGENT',
                DeviceFlowStatus::PENDING,
                2_000_000_000,
                2_000_000_600,
                5,
                0,
                null,
                null,
            ));
        $repository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (AuthDeviceFlow $flow): bool => $flow->status === DeviceFlowStatus::DENIED));

        $service = new AuthClientDeviceFlowLifecycleService($repository);
        $result = $service->cancelDeviceFlow('device-2');

        self::assertSame(DeviceFlowStatus::DENIED, $result['status'] ?? null);
    }
}
