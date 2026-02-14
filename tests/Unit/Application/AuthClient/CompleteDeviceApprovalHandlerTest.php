<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\AuthClient\ApproveDeviceFlowHandler;
use App\Application\AuthClient\CompleteDeviceApprovalHandler;
use App\Application\AuthClient\CompleteDeviceApprovalResult;
use App\Application\AuthClient\Port\AuthClientGateway;
use App\Application\AuthClient\Port\DeviceApprovalSecondFactorGateway;
use PHPUnit\Framework\TestCase;

final class CompleteDeviceApprovalHandlerTest extends TestCase
{
    public function testRequiresOtpWhenTwoFactorIsEnabled(): void
    {
        $secondFactor = $this->createMock(DeviceApprovalSecondFactorGateway::class);
        $secondFactor->expects(self::once())->method('isEnabled')->with('user-1')->willReturn(true);
        $secondFactor->expects(self::never())->method('verifyLoginOtp');

        $handler = new CompleteDeviceApprovalHandler($secondFactor, $this->makeApproveHandler(['status' => 'APPROVED']));
        $result = $handler->handle('user-1', 'CODE1234', '');

        self::assertSame(CompleteDeviceApprovalResult::STATUS_VALIDATION_FAILED_OTP_REQUIRED, $result->status());
    }

    public function testRejectsInvalidOtpWhenTwoFactorIsEnabled(): void
    {
        $secondFactor = $this->createMock(DeviceApprovalSecondFactorGateway::class);
        $secondFactor->expects(self::once())->method('isEnabled')->with('user-1')->willReturn(true);
        $secondFactor->expects(self::once())->method('verifyLoginOtp')->with('user-1', '000000')->willReturn(false);

        $handler = new CompleteDeviceApprovalHandler($secondFactor, $this->makeApproveHandler(['status' => 'APPROVED']));
        $result = $handler->handle('user-1', 'CODE1234', '000000');

        self::assertSame(CompleteDeviceApprovalResult::STATUS_INVALID_2FA_CODE, $result->status());
    }

    public function testMapsApprovalResultStatuses(): void
    {
        $secondFactor = $this->createMock(DeviceApprovalSecondFactorGateway::class);
        $secondFactor->method('isEnabled')->willReturn(false);

        $handler = new CompleteDeviceApprovalHandler($secondFactor, $this->makeApproveHandler(null));
        $result = $handler->handle('user-2', 'UNKNOWN', '');
        self::assertSame(CompleteDeviceApprovalResult::STATUS_INVALID_DEVICE_CODE, $result->status());
    }

    public function testReturnsSuccessWhenOtpIsValidAndApprovalSucceeds(): void
    {
        $secondFactor = $this->createMock(DeviceApprovalSecondFactorGateway::class);
        $secondFactor->expects(self::once())->method('isEnabled')->with('user-3')->willReturn(true);
        $secondFactor->expects(self::once())->method('verifyLoginOtp')->with('user-3', '123456')->willReturn(true);

        $handler = new CompleteDeviceApprovalHandler($secondFactor, $this->makeApproveHandler(['status' => 'APPROVED']));
        $result = $handler->handle('user-3', 'APPROVE1', '123456');

        self::assertSame(CompleteDeviceApprovalResult::STATUS_SUCCESS, $result->status());
    }

    /**
     * @param array{status: string}|null $approveStatus
     */
    private function makeApproveHandler(?array $approveStatus): ApproveDeviceFlowHandler
    {
        $gateway = new class ($approveStatus) implements AuthClientGateway {
            /**
             * @param array{status: string}|null $approveStatus
             */
            public function __construct(private ?array $approveStatus)
            {
            }

            public function isMcpDisabledByAppPolicy(): bool
            {
                return false;
            }

            public function mintToken(string $clientId, string $clientKind, string $secretKey): ?array
            {
                return null;
            }

            public function hasClient(string $clientId): bool
            {
                return false;
            }

            public function clientKind(string $clientId): ?string
            {
                return null;
            }

            public function revokeToken(string $clientId): bool
            {
                return false;
            }

            public function rotateSecret(string $clientId): ?string
            {
                return null;
            }

            public function startDeviceFlow(string $clientKind): array
            {
                return [];
            }

            public function pollDeviceFlow(string $deviceCode): ?array
            {
                return null;
            }

            public function cancelDeviceFlow(string $deviceCode): ?array
            {
                return null;
            }

            public function approveDeviceFlow(string $userCode): ?array
            {
                return $this->approveStatus;
            }
        };

        return new ApproveDeviceFlowHandler($gateway);
    }
}
