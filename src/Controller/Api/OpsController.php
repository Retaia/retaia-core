<?php

namespace App\Controller\Api;

use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAdminActorResult;
use App\Ingest\Repository\IngestDiagnosticsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/ops')]
final class OpsController
{
    use ApiErrorResponderTrait;

    public function __construct(
        private ResolveAdminActorHandler $resolveAdminActorHandler,
        private IngestDiagnosticsRepository $ingestDiagnostics,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/ingest/diagnostics', name: 'api_ops_ingest_diagnostics', methods: ['GET'])]
    public function ingestDiagnostics(): JsonResponse
    {
        if ($this->resolveAdminActorHandler->handle()->status() !== ResolveAdminActorResult::STATUS_AUTHORIZED) {
            return $this->errorResponse('FORBIDDEN_ACTOR', $this->translator->trans('auth.error.forbidden_actor'), Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->ingestDiagnostics->diagnosticsSnapshot(), Response::HTTP_OK);
    }
}

