<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/ops')]
final class OpsReadinessController
{
    public function __construct(
        private OpsAdminAccessGuard $adminAccessGuard,
        private OpsReadinessReportBuilder $readinessReportBuilder,
    ) {
    }

    #[Route('/readiness', name: 'api_ops_readiness', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->readinessReportBuilder->build(), Response::HTTP_OK);
    }
}
