<?php

namespace App\Auth;

use App\Domain\AuthClient\DeviceFlowStatus;

final class AuthClientDeviceFlowApprovalService
{
    public function __construct(
        private AuthDeviceFlowRepositoryInterface $deviceFlowRepository,
        private AuthClientProvisioningService $provisioningService,
    ) {
    }

    /**
     * @return array{status: string}|null
     */
    public function approveDeviceFlow(string $userCode): ?array
    {
        $normalizedUserCode = strtoupper(trim($userCode));
        if ($normalizedUserCode === '') {
            return null;
        }

        $matchedFlow = $this->deviceFlowRepository->findByUserCode($normalizedUserCode);
        if (!$matchedFlow instanceof AuthDeviceFlow) {
            return null;
        }

        $now = time();
        if ($matchedFlow->expiresAt < $now) {
            $this->deviceFlowRepository->save(new AuthDeviceFlow(
                $matchedFlow->deviceCode,
                $matchedFlow->userCode,
                $matchedFlow->clientKind,
                DeviceFlowStatus::EXPIRED,
                $matchedFlow->createdAt,
                $matchedFlow->expiresAt,
                $matchedFlow->intervalSeconds,
                $matchedFlow->lastPolledAt,
                $matchedFlow->approvedClientId,
                $matchedFlow->approvedSecretKey,
            ));

            return ['status' => DeviceFlowStatus::EXPIRED];
        }

        if ($matchedFlow->status === DeviceFlowStatus::DENIED) {
            return ['status' => DeviceFlowStatus::DENIED];
        }
        if ($matchedFlow->status === DeviceFlowStatus::APPROVED) {
            return ['status' => DeviceFlowStatus::APPROVED];
        }

        $credentials = $this->provisioningService->provisionClient($matchedFlow->clientKind);
        if ($credentials === null) {
            return null;
        }

        $this->deviceFlowRepository->save(new AuthDeviceFlow(
            $matchedFlow->deviceCode,
            $matchedFlow->userCode,
            $matchedFlow->clientKind,
            DeviceFlowStatus::APPROVED,
            $matchedFlow->createdAt,
            $matchedFlow->expiresAt,
            $matchedFlow->intervalSeconds,
            $matchedFlow->lastPolledAt,
            (string) $credentials['client_id'],
            (string) $credentials['secret_key'],
        ));

        return ['status' => DeviceFlowStatus::APPROVED];
    }
}
