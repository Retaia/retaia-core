<?php

namespace App\Auth;

use App\Domain\AuthClient\DeviceFlowStatus;

final class AuthClientDeviceFlowLifecycleService
{
    public function __construct(
        private AuthDeviceFlowRepositoryInterface $deviceFlowRepository,
    ) {
    }

    /**
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, expires_in: int, interval: int}
     */
    public function startDeviceFlow(string $clientKind): array
    {
        $deviceCode = 'dc_'.bin2hex(random_bytes(12));
        $userCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $now = time();
        $this->deviceFlowRepository->save(new AuthDeviceFlow(
            $deviceCode,
            $userCode,
            $clientKind,
            DeviceFlowStatus::PENDING,
            $now,
            $now + 600,
            5,
            0,
            null,
            null,
        ));

        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => '/device',
            'verification_uri_complete' => '/device?user_code='.$userCode,
            'expires_in' => 600,
            'interval' => 5,
        ];
    }

    /**
     * @return array{status: string, client_id?: string, client_kind?: string, secret_key?: string, interval?: int, retry_in_seconds?: int}|null
     */
    public function pollDeviceFlow(string $deviceCode): ?array
    {
        $flow = $this->deviceFlowRepository->findByDeviceCode($deviceCode);
        if (!$flow instanceof AuthDeviceFlow) {
            return null;
        }

        $now = time();
        if ($flow->expiresAt < $now) {
            $this->markExpired($flow);

            return ['status' => DeviceFlowStatus::EXPIRED];
        }

        $interval = $flow->intervalSeconds;
        $lastPolledAt = $flow->lastPolledAt;
        if ($lastPolledAt > 0 && ($now - $lastPolledAt) < $interval) {
            return [
                'status' => DeviceFlowStatus::PENDING,
                'interval' => $interval,
                'retry_in_seconds' => max(1, $interval - ($now - $lastPolledAt)),
            ];
        }

        $status = $flow->status;
        if ($status === DeviceFlowStatus::APPROVED) {
            $clientId = (string) ($flow->approvedClientId ?? '');
            $clientKind = $flow->clientKind;
            $secretKey = (string) ($flow->approvedSecretKey ?? '');
            if ($clientId !== '' && $clientKind !== '' && $secretKey !== '') {
                $this->deviceFlowRepository->delete($deviceCode);

                return [
                    'status' => DeviceFlowStatus::APPROVED,
                    'client_id' => $clientId,
                    'client_kind' => $clientKind,
                    'secret_key' => $secretKey,
                ];
            }
        }

        $this->deviceFlowRepository->save(new AuthDeviceFlow(
            $flow->deviceCode,
            $flow->userCode,
            $flow->clientKind,
            $flow->status,
            $flow->createdAt,
            $flow->expiresAt,
            $flow->intervalSeconds,
            $now,
            $flow->approvedClientId,
            $flow->approvedSecretKey,
        ));

        return ['status' => $status];
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        $flow = $this->deviceFlowRepository->findByDeviceCode($deviceCode);
        if (!$flow instanceof AuthDeviceFlow) {
            return null;
        }

        $now = time();
        if ($flow->expiresAt < $now) {
            return ['status' => DeviceFlowStatus::EXPIRED];
        }

        $this->deviceFlowRepository->save(new AuthDeviceFlow(
            $flow->deviceCode,
            $flow->userCode,
            $flow->clientKind,
            DeviceFlowStatus::DENIED,
            $flow->createdAt,
            $flow->expiresAt,
            $flow->intervalSeconds,
            $flow->lastPolledAt,
            $flow->approvedClientId,
            $flow->approvedSecretKey,
        ));

        return ['status' => DeviceFlowStatus::DENIED];
    }

    private function markExpired(AuthDeviceFlow $flow): void
    {
        $this->deviceFlowRepository->save(new AuthDeviceFlow(
            $flow->deviceCode,
            $flow->userCode,
            $flow->clientKind,
            DeviceFlowStatus::EXPIRED,
            $flow->createdAt,
            $flow->expiresAt,
            $flow->intervalSeconds,
            $flow->lastPolledAt,
            $flow->approvedClientId,
            $flow->approvedSecretKey,
        ));
    }
}
