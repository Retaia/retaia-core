<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ApiAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return null;
        }

        return new JsonResponse(
            [
                'code' => 'FORBIDDEN_SCOPE',
                'message' => $this->translator->trans('auth.error.forbidden_scope'),
            ],
            Response::HTTP_FORBIDDEN
        );
    }
}
