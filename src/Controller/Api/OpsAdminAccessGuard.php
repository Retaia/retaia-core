<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsAdminAccessGuard
{
    public function __construct(
        private ResolveAdminActorHandler $resolveAdminActorHandler,
        private TranslatorInterface $translator,
    ) {
    }

    public function requireAdmin(): ?JsonResponse
    {
        if ($this->resolveAdminActorHandler->handle()->status() === ResolveAdminActorResult::STATUS_AUTHORIZED) {
            return null;
        }

        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }
}
