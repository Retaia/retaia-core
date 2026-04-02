<?php

namespace App\Api\Service;

use App\Asset\AssetRevisionTag;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\ApiErrorResponseFactory;
use App\Entity\Asset;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetRequestPreconditionService
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private TranslatorInterface $translator,
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
            return ApiErrorResponseFactory::create(
                'PRECONDITION_REQUIRED',
                $this->translator->trans('asset.error.precondition_required'),
                Response::HTTP_PRECONDITION_REQUIRED,
                [
                    'current_revision_etag' => $currentRevisionEtag,
                    'current_state' => $asset->getState()->value,
                ]
            );
        }

        if ($ifMatch !== $currentRevisionEtag) {
            return ApiErrorResponseFactory::create(
                'PRECONDITION_FAILED',
                $this->translator->trans('asset.error.precondition_failed'),
                Response::HTTP_PRECONDITION_FAILED,
                [
                    'current_revision_etag' => $currentRevisionEtag,
                    'current_state' => $asset->getState()->value,
                ]
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
