<?php

namespace App\Application\AuthClient;

use App\Application\AuthClient\Input\DeviceCodeInput;
use App\Application\AuthClient\Input\StartDeviceFlowInput;

final class AuthClientDeviceFlowEndpointsHandler
{
    public function __construct(
        private StartDeviceFlowHandler $startDeviceFlowHandler,
        private PollDeviceFlowHandler $pollDeviceFlowHandler,
        private CancelDeviceFlowHandler $cancelDeviceFlowHandler,
        private Port\DeviceFlowStartRateLimiterGateway $startRateLimiter,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function start(array $payload, string $remoteAddress): StartDeviceFlowEndpointResult
    {
        $input = StartDeviceFlowInput::fromPayload($payload);
        if (!$input->isValid()) {
            return new StartDeviceFlowEndpointResult(StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED);
        }
        $clientKind = $input->clientKind();

        $retryInSeconds = $this->startRateLimiter->retryInSecondsOrNull($clientKind, $remoteAddress);
        if ($retryInSeconds !== null) {
            return new StartDeviceFlowEndpointResult(
                StartDeviceFlowEndpointResult::STATUS_TOO_MANY_ATTEMPTS,
                null,
                $retryInSeconds,
            );
        }

        $result = $this->startDeviceFlowHandler->handle($clientKind);
        if ($result->status() === StartDeviceFlowResult::STATUS_FORBIDDEN_ACTOR) {
            return new StartDeviceFlowEndpointResult(StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_ACTOR);
        }

        if ($result->status() === StartDeviceFlowResult::STATUS_FORBIDDEN_SCOPE) {
            return new StartDeviceFlowEndpointResult(StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_SCOPE);
        }

        return new StartDeviceFlowEndpointResult(StartDeviceFlowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function poll(array $payload): PollDeviceFlowEndpointResult
    {
        $input = DeviceCodeInput::fromPayload($payload);
        if (!$input->isValid()) {
            return new PollDeviceFlowEndpointResult(PollDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED);
        }
        $deviceCode = $input->deviceCode();

        $result = $this->pollDeviceFlowHandler->handle($deviceCode);
        if ($result->status() === PollDeviceFlowResult::STATUS_INVALID_DEVICE_CODE) {
            return new PollDeviceFlowEndpointResult(PollDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE);
        }

        if ($result->status() === PollDeviceFlowResult::STATUS_THROTTLED) {
            return new PollDeviceFlowEndpointResult(
                PollDeviceFlowEndpointResult::STATUS_THROTTLED,
                $result->payload(),
                (int) (($result->payload()['retry_in_seconds'] ?? null) ?? 0)
            );
        }

        return new PollDeviceFlowEndpointResult(PollDeviceFlowEndpointResult::STATUS_SUCCESS, $result->payload());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function cancel(array $payload): CancelDeviceFlowEndpointResult
    {
        $input = DeviceCodeInput::fromPayload($payload);
        if (!$input->isValid()) {
            return new CancelDeviceFlowEndpointResult(CancelDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED);
        }
        $deviceCode = $input->deviceCode();

        $result = $this->cancelDeviceFlowHandler->handle($deviceCode);
        if ($result->status() === CancelDeviceFlowResult::STATUS_INVALID_DEVICE_CODE) {
            return new CancelDeviceFlowEndpointResult(CancelDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE);
        }

        if ($result->status() === CancelDeviceFlowResult::STATUS_EXPIRED_DEVICE_CODE) {
            return new CancelDeviceFlowEndpointResult(CancelDeviceFlowEndpointResult::STATUS_EXPIRED_DEVICE_CODE);
        }

        return new CancelDeviceFlowEndpointResult(CancelDeviceFlowEndpointResult::STATUS_SUCCESS);
    }
}
