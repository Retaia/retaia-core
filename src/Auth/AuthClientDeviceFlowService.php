<?php

namespace App\Auth;

final class AuthClientDeviceFlowService
{
    public function __construct(
        private AuthClientStateStore $stateStore,
        private AuthClientProvisioningService $provisioningService,
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
        $flow = [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'client_kind' => $clientKind,
            'status' => 'PENDING',
            'created_at' => $now,
            'expires_at' => $now + 600,
            'interval' => 5,
            'last_polled_at' => 0,
        ];

        $flows = $this->stateStore->deviceFlows();
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

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
        $flows = $this->stateStore->deviceFlows();
        $flow = $flows[$deviceCode] ?? null;
        if (!is_array($flow)) {
            return null;
        }

        $now = time();
        if (($flow['expires_at'] ?? 0) < $now) {
            $flow['status'] = 'EXPIRED';
            $flows[$deviceCode] = $flow;
            $this->stateStore->saveDeviceFlows($flows);

            return ['status' => 'EXPIRED'];
        }

        $interval = (int) ($flow['interval'] ?? 5);
        $lastPolledAt = (int) ($flow['last_polled_at'] ?? 0);
        if ($lastPolledAt > 0 && ($now - $lastPolledAt) < $interval) {
            return [
                'status' => 'PENDING',
                'interval' => $interval,
                'retry_in_seconds' => max(1, $interval - ($now - $lastPolledAt)),
            ];
        }

        $status = (string) ($flow['status'] ?? 'PENDING');
        if ($status === 'APPROVED') {
            $clientId = (string) ($flow['approved_client_id'] ?? '');
            $clientKind = (string) ($flow['client_kind'] ?? '');
            $secretKey = (string) ($flow['approved_secret_key'] ?? '');
            if ($clientId !== '' && $clientKind !== '' && $secretKey !== '') {
                unset($flows[$deviceCode]);
                $this->stateStore->saveDeviceFlows($flows);

                return [
                    'status' => 'APPROVED',
                    'client_id' => $clientId,
                    'client_kind' => $clientKind,
                    'secret_key' => $secretKey,
                ];
            }
        }

        $flow['last_polled_at'] = $now;
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => $status];
    }

    /**
     * @return array{status: string}|null
     */
    public function cancelDeviceFlow(string $deviceCode): ?array
    {
        $flows = $this->stateStore->deviceFlows();
        $flow = $flows[$deviceCode] ?? null;
        if (!is_array($flow)) {
            return null;
        }

        $now = time();
        if (($flow['expires_at'] ?? 0) < $now) {
            return ['status' => 'EXPIRED'];
        }

        $flow['status'] = 'DENIED';
        $flows[$deviceCode] = $flow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => 'DENIED'];
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

        $flows = $this->stateStore->deviceFlows();
        $matchedKey = null;
        $matchedFlow = null;
        foreach ($flows as $deviceCode => $flow) {
            if (!is_array($flow)) {
                continue;
            }
            if (strtoupper((string) ($flow['user_code'] ?? '')) !== $normalizedUserCode) {
                continue;
            }
            $matchedKey = is_string($deviceCode) ? $deviceCode : null;
            $matchedFlow = $flow;
            break;
        }

        if ($matchedKey === null || !is_array($matchedFlow)) {
            return null;
        }

        $now = time();
        if (($matchedFlow['expires_at'] ?? 0) < $now) {
            $matchedFlow['status'] = 'EXPIRED';
            $flows[$matchedKey] = $matchedFlow;
            $this->stateStore->saveDeviceFlows($flows);

            return ['status' => 'EXPIRED'];
        }

        if (($matchedFlow['status'] ?? '') === 'DENIED') {
            return ['status' => 'DENIED'];
        }
        if (($matchedFlow['status'] ?? '') === 'APPROVED') {
            return ['status' => 'APPROVED'];
        }

        $clientKind = (string) ($matchedFlow['client_kind'] ?? '');
        $credentials = $this->provisioningService->provisionClient($clientKind);
        if ($credentials === null) {
            return null;
        }

        $matchedFlow['status'] = 'APPROVED';
        $matchedFlow['approved_client_id'] = $credentials['client_id'];
        $matchedFlow['approved_secret_key'] = $credentials['secret_key'];
        $flows[$matchedKey] = $matchedFlow;
        $this->stateStore->saveDeviceFlows($flows);

        return ['status' => 'APPROVED'];
    }
}
