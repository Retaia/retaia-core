<?php

namespace App\Security;

use App\Controller\Api\ApiErrorResponseFactory;
use App\Entity\User;
use App\User\Service\TwoFactorService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiLoginSecondFactorChallengeResponder
{
    public function __construct(
        private TwoFactorService $twoFactorService,
        private ApiLoginSecondFactorAttemptLimiter $attemptLimiter,
        private TranslatorInterface $translator,
        private ApiLoginRequestDataExtractor $requestDataExtractor,
    ) {
    }

    public function handle(Request $request, User $user): ?JsonResponse
    {
        if (!$this->twoFactorService->isEnabled($user->getId())) {
            return null;
        }

        $secondFactor = $this->requestDataExtractor->secondFactor($request);
        $otpCode = $secondFactor['otp_code'];
        $recoveryCode = $secondFactor['recovery_code'];

        if ($otpCode === '' && $recoveryCode === '') {
            return ApiErrorResponseFactory::create(
                'MFA_REQUIRED',
                $this->translator->trans('auth.error.mfa_required'),
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$this->attemptLimiter->consume((string) $user->getId(), (string) ($request->getClientIp() ?? 'unknown'))) {
            return $this->attemptLimiter->tooManyAttemptsResponse();
        }

        $secondFactorOk = $otpCode !== ''
            ? $this->twoFactorService->verifyLoginOtp($user->getId(), $otpCode)
            : $this->twoFactorService->consumeRecoveryCode($user->getId(), $recoveryCode);

        if ($secondFactorOk) {
            return null;
        }

        return ApiErrorResponseFactory::create(
            'INVALID_2FA_CODE',
            $this->translator->trans('auth.error.invalid_2fa_code'),
            Response::HTTP_UNAUTHORIZED
        );
    }
}
