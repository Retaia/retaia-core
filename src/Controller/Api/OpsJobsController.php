<?php

namespace App\Controller\Api;

use App\Job\Repository\JobRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/ops')]
final class OpsJobsController
{
    public function __construct(
        private OpsAdminAccessGuard $adminAccessGuard,
        private JobRepository $jobs,
    ) {
    }

    #[Route('/jobs/queue', name: 'api_ops_jobs_queue', methods: ['GET'])]
    public function queue(): JsonResponse
    {
        $forbidden = $this->adminAccessGuard->requireAdmin();
        if ($forbidden instanceof JsonResponse) {
            return $forbidden;
        }

        return new JsonResponse($this->jobs->queueDiagnosticsSnapshot(), Response::HTTP_OK);
    }
}
