<?php

namespace App\Api\Service;

use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AssetRequestPreconditionService
{
    public function __construct(
        private AssetRepositoryInterface $assets,
    ) {
    }

    public function violationResponse(Request $request, string $assetUuid): ?JsonResponse
    {
        $asset = $this->assets->findByUuid($assetUuid);
        if (!$asset instanceof Asset) {
            return null;
        }

        $currentRevisionEtag = AssetRevisionTag::fromAsset($asset);
        $ifMatch = trim((string) $request->headers->get('If-Match', ''));
        if ($ifMatch === '') {
            return new JsonResponse(
                [
                    'code' => 'PRECONDITION_REQUIRED',
                    'message' => 'Missing If-Match header',
                    'details' => [
                        'current_revision_etag' => $currentRevisionEtag,
                        'current_state' => $asset->getState()->value,
                    ],
                ],
                Response::HTTP_PRECONDITION_REQUIRED
            );
        }

        if ($ifMatch !== $currentRevisionEtag) {
            return new JsonResponse(
                [
                    'code' => 'PRECONDITION_FAILED',
                    'message' => 'Stale asset revision',
                    'details' => [
                        'current_revision_etag' => $currentRevisionEtag,
                        'current_state' => $asset->getState()->value,
                    ],
                ],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function attachResponseEtagFromPayload(JsonResponse $response, array $payload): JsonResponse
    {
        $etag = AssetRevisionTag::fromPayload($payload);
        if (is_string($etag)) {
            $response->headers->set('ETag', $etag);
        }

        return $response;
    }
}
