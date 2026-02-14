<?php

namespace App\Controller;

use App\Auth\AuthClientService;
use App\Entity\User;
use App\User\Service\TwoFactorService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/device')]
final class DeviceController
{
    public function __construct(
        private Security $security,
        private TwoFactorService $twoFactorService,
        private AuthClientService $authClientService,
        private TranslatorInterface $translator,
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => $this->translator->trans('auth.error.authentication_required')],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $payload = $this->payload($request);
        $userCode = strtoupper(trim((string) ($payload['user_code'] ?? '')));
        if ($userCode === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.user_code_required')],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if ($this->twoFactorService->isEnabled($user->getId())) {
            $otpCode = trim((string) ($payload['otp_code'] ?? ''));
            if ($otpCode === '') {
                return new JsonResponse(
                    ['code' => 'VALIDATION_FAILED', 'message' => $this->translator->trans('auth.error.otp_code_required')],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            if (!$this->twoFactorService->verifyLoginOtp($user->getId(), $otpCode)) {
                return new JsonResponse(
                    ['code' => 'INVALID_2FA_CODE', 'message' => $this->translator->trans('auth.error.invalid_2fa_code')],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $status = $this->authClientService->approveDeviceFlow($userCode);
        if (!is_array($status)) {
            return new JsonResponse(
                ['code' => 'INVALID_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.invalid_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }
        if (($status['status'] ?? null) === 'EXPIRED') {
            return new JsonResponse(
                ['code' => 'EXPIRED_DEVICE_CODE', 'message' => $this->translator->trans('auth.error.expired_device_code')],
                Response::HTTP_BAD_REQUEST
            );
        }
        if (($status['status'] ?? null) !== 'APPROVED') {
            return new JsonResponse(
                ['code' => 'STATE_CONFLICT', 'message' => $this->translator->trans('auth.error.device_flow_not_approvable')],
                Response::HTTP_CONFLICT
            );
        }

        return new JsonResponse(['approved' => true], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return $request->request->all();
        }

        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $request->request->all();
    }
}
