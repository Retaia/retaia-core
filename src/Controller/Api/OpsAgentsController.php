<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/ops')]
final class OpsAgentsController
{
    public function __construct(
        private OpsAdminAccessGuard $adminAccessGuard,
        private OpsAgentsViewProjector $agentsViewProjector,
    ) {
    }

    #[Route('/agents', name: 'api_ops_agents', methods: ['GET'])]
    public function agents(Request $request): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        $statusFilter = trim((string) $request->query->get('status', ''));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        return new JsonResponse(
            $this->agentsViewProjector->paginated($statusFilter !== '' ? $statusFilter : null, $limit, $offset),
            Response::HTTP_OK
        );
    }
}
