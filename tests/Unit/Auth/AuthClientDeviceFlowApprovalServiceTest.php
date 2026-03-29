<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientDeviceFlowApprovalService;
use App\Auth\AuthClientProvisioningService;
use App\Auth\AuthDeviceFlow;
use App\Auth\AuthDeviceFlowRepositoryInterface;
use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthClientRegistryRepositoryInterface;
use App\Domain\AuthClient\DeviceFlowStatus;
use PHPUnit\Framework\TestCase;

final class AuthClientDeviceFlowApprovalServiceTest extends TestCase
{
    public function testApproveStoresProvisionedClientCredentials(): void
    {
        $repository = $this->createMock(AuthDeviceFlowRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByUserCode')
            ->with('ABCD1234')
            ->willReturn(new AuthDeviceFlow(
                'device-1',
                'ABCD1234',
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
            ->with(self::callback(static fn (AuthDeviceFlow $flow): bool => $flow->status === DeviceFlowStatus::APPROVED
                && is_string($flow->approvedClientId)
                && $flow->approvedClientId !== ''
                && is_string($flow->approvedSecretKey)
                && $flow->approvedSecretKey !== ''));

        $registryRepository = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registryRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (AuthClientRegistryEntry $entry): bool => $entry->clientKind === 'AGENT'));
        $provisioning = new AuthClientProvisioningService($registryRepository);

        $service = new AuthClientDeviceFlowApprovalService($repository, $provisioning);
        $result = $service->approveDeviceFlow(' abcd1234 ');

        self::assertSame(DeviceFlowStatus::APPROVED, $result['status'] ?? null);
    }

    public function testApproveReturnsDeniedWithoutProvisioningWhenFlowAlreadyDenied(): void
    {
        $repository = $this->createMock(AuthDeviceFlowRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByUserCode')
            ->with('ABCD1234')
            ->willReturn(new AuthDeviceFlow(
                'device-1',
                'ABCD1234',
                'AGENT',
                DeviceFlowStatus::DENIED,
                2_000_000_000,
                2_000_000_600,
                5,
                0,
                null,
                null,
            ));
        $repository->expects(self::never())->method('save');

        $registryRepository = $this->createMock(AuthClientRegistryRepositoryInterface::class);
        $registryRepository->expects(self::never())->method('save');
        $provisioning = new AuthClientProvisioningService($registryRepository);

        $service = new AuthClientDeviceFlowApprovalService($repository, $provisioning);
        $result = $service->approveDeviceFlow('ABCD1234');

        self::assertSame(DeviceFlowStatus::DENIED, $result['status'] ?? null);
    }
}
