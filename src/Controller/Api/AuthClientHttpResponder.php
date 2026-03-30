<?php

namespace App\Controller\Api;

use App\Application\AuthClient\CancelDeviceFlowEndpointResult;
use App\Application\AuthClient\MintClientTokenEndpointResult;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Application\AuthClient\RevokeClientTokenEndpointResult;
use App\Application\AuthClient\RotateClientSecretEndpointResult;
use App\Application\AuthClient\StartDeviceFlowEndpointResult;
use App\Domain\AuthClient\DeviceFlowStatus;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthClientHttpResponder
{
    public function __construct(
        private AuthApiErrorResponder $errors,
        private MetricEventRepository $metrics,
        private LoggerInterface $logger,
    ) {
    }

    public function clientToken(MintClientTokenEndpointResult $result): JsonResponse
    {
        if ($result->status() === MintClientTokenEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errors->validationFailed('auth.error.client_credentials_required');
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return $this->errors->tooManyAttempts(
                'auth.error.too_many_client_token_requests',
                $result->retryInSeconds() ?? 60
            );
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            $this->metrics->record(MetricName::AUTH_CLIENT_TOKEN_FORBIDDEN_ACTOR_UI_WEB);

            return $this->errors->forbiddenActor();
        }
        if ($result->status() === MintClientTokenEndpointResult::STATUS_UNAUTHORIZED || !is_array($result->token())) {
            return $this->errors->translated(
                'UNAUTHORIZED',
                'auth.error.invalid_client_credentials',
                Response::HTTP_UNAUTHORIZED
            );
        }

        $this->logger->info('auth.client.token.minted', [
            'client_id' => $result->clientId(),
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse($result->token(), Response::HTTP_OK);
    }

    public function revokeClientToken(string $clientId, RevokeClientTokenEndpointResult $result): JsonResponse
    {
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_UNAUTHORIZED) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->errors->forbiddenActor();
        }
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errors->validationFailed('auth.error.invalid_client_id');
        }
        if ($result->status() === RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_SCOPE) {
            return $this->errors->forbiddenScope();
        }

        $this->logger->info('auth.client.token.revoked', [
            'client_id' => $clientId,
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse(['revoked' => true, 'client_id' => $clientId], Response::HTTP_OK);
    }

    public function rotateClientSecret(string $clientId, RotateClientSecretEndpointResult $result): JsonResponse
    {
        if ($result->status() === RotateClientSecretEndpointResult::STATUS_UNAUTHORIZED) {
            return $this->errors->unauthorizedAuthenticationRequired();
        }
        if ($result->status() === RotateClientSecretEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->errors->forbiddenActor();
        }
        if ($result->status() === RotateClientSecretEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errors->validationFailed('auth.error.invalid_client_id');
        }

        $this->logger->info('auth.client.secret.rotated', [
            'client_id' => $clientId,
            'client_kind' => $result->clientKind(),
        ]);

        return new JsonResponse([
            'client_id' => $clientId,
            'secret_key' => $result->secretKey(),
            'rotated' => true,
        ], Response::HTTP_OK);
    }

    public function startDeviceFlow(StartDeviceFlowEndpointResult $result): JsonResponse
    {
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errors->validationFailed('auth.error.client_kind_required');
        }
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_TOO_MANY_ATTEMPTS) {
            return $this->errors->tooManyAttempts(
                'auth.error.too_many_client_token_requests',
                $result->retryInSeconds() ?? 60
            );
        }
        if ($result->status() === StartDeviceFlowEndpointResult::STATUS_FORBIDDEN_ACTOR) {
            return $this->errors->forbiddenActor();
        }

        return new JsonResponse($result->payload(), Response::HTTP_OK);
    }

    public function pollDeviceFlow(PollDeviceFlowEndpointResult $result): JsonResponse
    {
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED) {
            return $this->errors->validationFailed('auth.error.device_code_required');
        }
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE) {
            $this->metrics->record(MetricName::AUTH_DEVICE_POLL_INVALID_DEVICE_CODE);

            return $this->errors->invalidDeviceCode();
        }

        $status = $result->payload();
        if ($result->status() === PollDeviceFlowEndpointResult::STATUS_THROTTLED && is_array($status)) {
            $this->metrics->record(MetricName::AUTH_DEVICE_POLL_THROTTLED);

            return $this->errors->slowDown($result->retryInSeconds() ?? (int) ($status['retry_in_seconds'] ?? 0));
        }

        if (!is_array($status)) {
            return $this->errors->invalidDeviceCode();
        }

        $flowStatus = strtoupper((string) ($status['status'] ?? ''));
        if (DeviceFlowStatus::isKnown($flowStatus)) {
            $this->metrics->record(MetricName::authDevicePollStatus($flowStatus));
            if ($flowStatus === DeviceFlowStatus::APPROVED) {
                $this->logger->info('auth.device.approved');
            }
            if ($flowStatus === DeviceFlowStatus::DENIED) {
                $this->logger->warning('auth.device.denied');
            }
        }

        return new JsonResponse($status, Response::HTTP_OK);
    }

    public function cancelDeviceFlow(CancelDeviceFlowEndpointResult $result): JsonResponse
    {
        return match ($result->status()) {
            CancelDeviceFlowEndpointResult::STATUS_VALIDATION_FAILED => $this->errors->validationFailed('auth.error.device_code_required'),
            CancelDeviceFlowEndpointResult::STATUS_INVALID_DEVICE_CODE => $this->errors->invalidDeviceCode(),
            CancelDeviceFlowEndpointResult::STATUS_EXPIRED_DEVICE_CODE => $this->errors->expiredDeviceCode(),
            default => new JsonResponse(['canceled' => true], Response::HTTP_OK),
        };
    }
}
