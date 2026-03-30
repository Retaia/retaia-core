<?php

namespace App\Controller\Api;

use App\Auth\UserAccessTokenService;
use Symfony\Component\HttpFoundation\Request;

final class AuthCurrentSessionResolver
{
    public function __construct(
        private UserAccessTokenService $userAccessTokenService,
    ) {
    }

    /**
     * @return array{user_id: string, email: string, client_id: string, client_kind: string, session_id: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        return $this->userAccessTokenService->validate(trim(substr($authorization, 7)));
    }
}
