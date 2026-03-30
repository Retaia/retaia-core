<?php

namespace App\Tests\Unit\Auth;

use App\Auth\AuthClientDeviceFlowApprovalService;
use App\Auth\AuthClientDeviceFlowLifecycleService;
use App\Auth\AuthClientDeviceFlowService;
use App\Auth\AuthClientProvisioningService;
use App\Auth\AuthDeviceFlow;
use App\Auth\AuthDeviceFlowRepositoryInterface;
use App\Domain\AuthClient\ClientKind;
use App\Domain\AuthClient\DeviceFlowStatus;
use PHPUnit\Framework\TestCase;

final class AuthClientDeviceFlowServiceTest extends TestCase
{
    public function testFacadeDelegatesToLifecycleAndApprovalServices(): void
    {
        $repository = new class implements AuthDeviceFlowRepositoryInterface {
            /** @var array<string, AuthDeviceFlow> */
            public array $flows = [];

            public function findByDeviceCode(string $deviceCode): ?AuthDeviceFlow
            {
                return $this->flows[$deviceCode] ?? null;
            }

            public function findByUserCode(string $userCode): ?AuthDeviceFlow
            {
                foreach ($this->flows as $flow) {
                    if ($flow->userCode === $userCode) {
                        return $flow;
                    }
                }

                return null;
            }

            public function save(AuthDeviceFlow $flow): void
            {
                $this->flows[$flow->deviceCode] = $flow;
            }

            public function delete(string $deviceCode): void
            {
                unset($this->flows[$deviceCode]);
            }
        };

        $lifecycle = new AuthClientDeviceFlowLifecycleService($repository);
        $approval = new AuthClientDeviceFlowApprovalService($repository, new AuthClientProvisioningService($this->createMock(\App\Auth\AuthClientRegistryRepositoryInterface::class)));

        $service = new AuthClientDeviceFlowService($lifecycle, $approval);
        $started = $service->startDeviceFlow(ClientKind::AGENT);

        self::assertSame('/device', $started['verification_uri']);
        self::assertSame(DeviceFlowStatus::PENDING, $service->pollDeviceFlow($started['device_code'])['status'] ?? null);
        self::assertSame(DeviceFlowStatus::APPROVED, $service->approveDeviceFlow($started['user_code'])['status'] ?? null);
    }
}
