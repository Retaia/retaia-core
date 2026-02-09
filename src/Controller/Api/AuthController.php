<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\User\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

#[Route('/api/v1/auth')]
final class AuthController
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private Security $security,
        private PasswordResetService $passwordResetService,
    ) {
    }

    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        throw new \LogicException('This endpoint is handled by the security authenticator.');
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        throw new \LogicException('This endpoint is handled by the firewall logout.');
    }

    #[Route('/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(
                ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new JsonResponse(
            [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            Response::HTTP_OK
        );
    }

    #[Route('/lost-password/request', name: 'api_auth_lost_password_request', methods: ['POST'])]
    public function requestReset(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => 'email is required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $token = $this->passwordResetService->requestReset($email);
        $response = ['accepted' => true];
        if ($token !== null) {
            $response['reset_token'] = $token;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }

    #[Route('/lost-password/reset', name: 'api_auth_lost_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $token = trim((string) ($payload['token'] ?? ''));
        $newPassword = (string) ($payload['new_password'] ?? '');

        if ($token === '' || $newPassword === '') {
            return new JsonResponse(
                ['code' => 'VALIDATION_FAILED', 'message' => 'token and new_password are required'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return new JsonResponse(
                [
                    'code' => 'VALIDATION_FAILED',
                    'message' => sprintf('new_password must be at least %d characters', self::MIN_PASSWORD_LENGTH),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (!$this->passwordResetService->resetPassword($token, $newPassword)) {
            return new JsonResponse(
                ['code' => 'INVALID_TOKEN', 'message' => 'Token invalid or expired'],
                Response::HTTP_BAD_REQUEST
            );
        }

        return new JsonResponse(['password_reset' => true], Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
