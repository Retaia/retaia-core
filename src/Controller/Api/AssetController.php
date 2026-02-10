<?php

namespace App\Controller\Api;

use App\Api\Service\IdempotencyService;
use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use Symfony\Bundle\SecurityBundle\Security;
use App\Entity\User;
use App\Lock\Repository\OperationLockRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/assets')]
final class AssetController
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private AssetStateMachine $stateMachine,
        private TranslatorInterface $translator,
        private Security $security,
        private IdempotencyService $idempotency,
        private OperationLockRepository $locks,
    ) {
    }

    #[Route('', name: 'api_assets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $state = $request->query->get('state');
        $mediaType = $request->query->get('media_type');
        $query = $request->query->get('q');
        $limit = max(1, (int) $request->query->get('limit', 50));

        $assets = $this->assets->listAssets(
            is_string($state) ? $state : null,
            is_string($mediaType) ? $mediaType : null,
            is_string($query) ? $query : null,
            $limit,
        );

        return new JsonResponse([
            'items' => array_map(fn (Asset $asset): array => $this->summary($asset), $assets),
            'next_cursor' => null,
        ], Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_get', methods: ['GET'])]
    public function getOne(string $uuid): JsonResponse
    {
        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->detail($asset), Response::HTTP_OK);
    }

    #[Route('/{uuid}', name: 'api_assets_patch', methods: ['PATCH'])]
    public function patch(string $uuid, Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActorResponse();
        }

        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($asset->getState() === AssetState::PURGED) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.purged_read_only'),
            ], Response::HTTP_GONE);
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        $payload = $this->payload($request);
        if (array_key_exists('tags', $payload) && is_array($payload['tags'])) {
            $asset->setTags($payload['tags']);
        }

        if (array_key_exists('notes', $payload)) {
            $asset->setNotes(is_string($payload['notes']) ? $payload['notes'] : null);
        }

        if (array_key_exists('fields', $payload) && is_array($payload['fields'])) {
            $asset->setFields($payload['fields']);
        }

        $this->assets->save($asset);

        return new JsonResponse($this->detail($asset), Response::HTTP_OK);
    }

    #[Route('/{uuid}/decision', name: 'api_assets_decision', methods: ['POST'])]
    public function decision(string $uuid, Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid, $request): JsonResponse {
            $asset = $this->assets->findByUuid($uuid);
            if (!$asset instanceof Asset) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($this->locks->hasActiveLock($asset->getUuid())) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            $payload = $this->payload($request);
            $action = trim((string) ($payload['action'] ?? ''));
            if ($action === '') {
                return new JsonResponse([
                    'code' => 'VALIDATION_FAILED',
                    'message' => $this->translator->trans('asset.error.decision_action_required'),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            try {
                $this->stateMachine->decide($asset, $action);
                $this->assets->save($asset);
            } catch (StateConflictException $exception) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse([
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
            ], Response::HTTP_OK);
        });
    }

    #[Route('/{uuid}/reopen', name: 'api_assets_reopen', methods: ['POST'])]
    public function reopen(string $uuid): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActorResponse();
        }

        $asset = $this->assets->findByUuid($uuid);
        if (!$asset instanceof Asset) {
            return new JsonResponse([
                'code' => 'NOT_FOUND',
                'message' => $this->translator->trans('asset.error.not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($this->locks->hasActiveLock($asset->getUuid())) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        try {
            $this->stateMachine->transition($asset, AssetState::DECISION_PENDING);
            $this->assets->save($asset);
        } catch (StateConflictException $exception) {
            return new JsonResponse([
                'code' => 'STATE_CONFLICT',
                'message' => $this->translator->trans('asset.error.state_conflict'),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'uuid' => $asset->getUuid(),
            'state' => $asset->getState()->value,
        ], Response::HTTP_OK);
    }

    #[Route('/{uuid}/reprocess', name: 'api_assets_reprocess', methods: ['POST'])]
    public function reprocess(string $uuid, Request $request): JsonResponse
    {
        if ($this->security->isGranted('ROLE_AGENT')) {
            return $this->forbiddenActorResponse();
        }

        return $this->idempotency->execute($request, $this->actorId(), function () use ($uuid): JsonResponse {
            $asset = $this->assets->findByUuid($uuid);
            if (!$asset instanceof Asset) {
                return new JsonResponse([
                    'code' => 'NOT_FOUND',
                    'message' => $this->translator->trans('asset.error.not_found'),
                ], Response::HTTP_NOT_FOUND);
            }

            if ($this->locks->hasActiveLock($asset->getUuid())) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            try {
                $this->stateMachine->transition($asset, AssetState::READY);
                $this->assets->save($asset);
            } catch (StateConflictException $exception) {
                return new JsonResponse([
                    'code' => 'STATE_CONFLICT',
                    'message' => $this->translator->trans('asset.error.state_conflict'),
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse([
                'uuid' => $asset->getUuid(),
                'state' => $asset->getState()->value,
            ], Response::HTTP_OK);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
            'notes' => $asset->getNotes(),
            'fields' => $asset->getFields(),
            'created_at' => $asset->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $asset->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Asset $asset): array
    {
        return [
            'uuid' => $asset->getUuid(),
            'media_type' => $asset->getMediaType(),
            'filename' => $asset->getFilename(),
            'state' => $asset->getState()->value,
            'tags' => $asset->getTags(),
        ];
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

    private function forbiddenActorResponse(): JsonResponse
    {
        return new JsonResponse([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => $this->translator->trans('auth.error.forbidden_actor'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function actorId(): string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : 'anonymous';
    }
}
