<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Entity\User;
use App\Job\Repository\JobRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/jobs')]
final class JobController
{
    public function __construct(
        private JobRepository $jobs,
        private IdempotencyService $idempotency,
        private Security $security,
    ) {
    }

    #[Route('', name: 'api_jobs_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, (int) $request->query->get('limit', 20));

        $jobs = $this->jobs->listClaimable($limit);

        return new JsonResponse([
            'items' => array_map(static fn ($job): array => $job->toArray(), $jobs),
        ], Response::HTTP_OK);
    }

    #[Route('/{jobId}/claim', name: 'api_jobs_claim', methods: ['POST'])]
    public function claim(string $jobId): JsonResponse
    {
        $job = $this->jobs->claim($jobId, $this->actorId(), 300);
        if ($job === null) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Job is not claimable',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse($job->toArray(), Response::HTTP_OK);
    }

    #[Route('/{jobId}/heartbeat', name: 'api_jobs_heartbeat', methods: ['POST'])]
    public function heartbeat(string $jobId, Request $request): JsonResponse
    {
        $lockToken = trim((string) ($this->payload($request)['lock_token'] ?? ''));
        if ($lockToken === '') {
            return new JsonResponse([
                'code' => 'VALIDATION_FAILED',
                'message' => 'lock_token is required',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = $this->jobs->heartbeat($jobId, $lockToken, 300);
        if ($job === null) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => 'Invalid lock token or expired lock',
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'locked_until' => $job->lockedUntil?->format(DATE_ATOM),
        ], Response::HTTP_OK);
    }

    #[Route('/{jobId}/submit', name: 'api_jobs_submit', methods: ['POST'])]
    public function submit(string $jobId, Request $request): JsonResponse
    {
        return $this->idempotency->execute($request, $this->actorId(), function () use ($jobId, $request): JsonResponse {
            $payload = $this->payload($request);
            $lockToken = trim((string) ($payload['lock_token'] ?? ''));
            if ($lockToken === '') {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'lock_token is required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $result = $payload['result'] ?? [];
            if (!is_array($result)) {
                $result = [];
            }

            $job = $this->jobs->submit($jobId, $lockToken, $result);
            if ($job === null) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => 'Invalid lock token or expired lock',
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($job->toArray(), Response::HTTP_OK);
        });
    }

    #[Route('/{jobId}/fail', name: 'api_jobs_fail', methods: ['POST'])]
    public function fail(string $jobId, Request $request): JsonResponse
    {
        return $this->idempotency->execute($request, $this->actorId(), function () use ($jobId, $request): JsonResponse {
            $payload = $this->payload($request);
            $lockToken = trim((string) ($payload['lock_token'] ?? ''));
            $errorCode = trim((string) ($payload['error_code'] ?? ''));
            $message = trim((string) ($payload['message'] ?? ''));
            $retryable = (bool) ($payload['retryable'] ?? false);

            if ($lockToken === '' || $errorCode === '' || $message === '') {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'lock_token, error_code and message are required',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $job = $this->jobs->fail($jobId, $lockToken, $retryable, $errorCode, $message);
            if ($job === null) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => 'Invalid lock token or expired lock',
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse($job->toArray(), Response::HTTP_OK);
        });
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

    private function actorId(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : 'anonymous';
    }
}
