<?php

namespace App\Controller;

use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\Auth\ResolveAuthenticatedUserResult;
use App\Application\AuthClient\CompleteDeviceApprovalHandler;
use App\Application\AuthClient\CompleteDeviceApprovalResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/device')]
final class DeviceController
{
    use \App\Controller\Api\ApiErrorResponderTrait;
    use RequestPayloadTrait;

    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUserHandler,
        private CompleteDeviceApprovalHandler $completeDeviceApprovalHandler,
        private TranslatorInterface $translator,
        #[Autowire(service: 'limiter.auth_2fa_challenge')]
        private RateLimiterFactory $twoFactorChallengeRateLimiter,
    ) {
    }

    #[Route('', name: 'device_approval_info', methods: ['GET'])]
    public function info(Request $request): JsonResponse
    {
        return new JsonResponse(
            [
                'device_approval_required' => true,
                'user_code' => trim((string) $request->query->get('user_code', '')),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('', name: 'device_approve', methods: ['POST'])]
    public function approve(Request $request): JsonResponse
    {
        $authenticatedUser = $this->resolveAuthenticatedUserHandler->handle();
        if ($authenticatedUser->status() === ResolveAuthenticatedUserResult::STATUS_UNAUTHORIZED) {
            return $this->errorResponse('UNAUTHORIZED', $this->translator->trans('auth.error.authentication_required'), Response::HTTP_UNAUTHORIZED);
        }

        $payload = $this->payload($request, true);
        $userCode = strtoupper(trim((string) ($payload['user_code'] ?? '')));
        if ($userCode === '') {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('auth.error.user_code_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $otpCode = trim((string) ($payload['otp_code'] ?? ''));
        $limit = $this->twoFactorChallengeRateLimiter
            ->create(hash('sha256', (string) $authenticatedUser->id().'|'.(string) ($request->getClientIp() ?? 'unknown').'|device-approve'))
            ->consume(1);
        if (!$limit->isAccepted()) {
            return $this->errorResponse('TOO_MANY_ATTEMPTS', $this->translator->trans('auth.error.too_many_2fa_attempts'), Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = $this->completeDeviceApprovalHandler->handle(
            (string) $authenticatedUser->id(),
            $userCode,
            $otpCode
        );
        if ($result->status() === CompleteDeviceApprovalResult::STATUS_VALIDATION_FAILED_OTP_REQUIRED) {
            return $this->errorResponse('VALIDATION_FAILED', $this->translator->trans('auth.error.otp_code_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result->status() === CompleteDeviceApprovalResult::STATUS_INVALID_2FA_CODE) {
            return $this->errorResponse('INVALID_2FA_CODE', $this->translator->trans('auth.error.invalid_2fa_code'), Response::HTTP_BAD_REQUEST);
        }
        if ($result->status() === CompleteDeviceApprovalResult::STATUS_INVALID_DEVICE_CODE) {
            return $this->errorResponse('INVALID_DEVICE_CODE', $this->translator->trans('auth.error.invalid_device_code'), Response::HTTP_BAD_REQUEST);
        }
        if ($result->status() === CompleteDeviceApprovalResult::STATUS_EXPIRED_DEVICE_CODE) {
            return $this->errorResponse('EXPIRED_DEVICE_CODE', $this->translator->trans('auth.error.expired_device_code'), Response::HTTP_BAD_REQUEST);
        }
        if ($result->status() === CompleteDeviceApprovalResult::STATUS_STATE_CONFLICT) {
            return $this->errorResponse('STATE_CONFLICT', $this->translator->trans('auth.error.device_flow_not_approvable'), Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['approved' => true], Response::HTTP_OK);
    }
}
