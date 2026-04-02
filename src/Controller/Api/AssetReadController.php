<?php

namespace App\Controller\Api;

use App\Application\Asset\AssetEndpointsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/assets')]
final class AssetReadController
{
    public function __construct(
        private AssetEndpointsHandler $assetEndpointsHandler,
        private AssetHttpResponder $responder,
        private AssetListQueryParser $queryParser,
    ) {
    }

    #[Route('', name: 'api_assets_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $this->queryParser->parse($request);
        $result = $this->assetEndpointsHandler->list(
            $query['states'],
            $query['mediaType'],
            $query['query'],
            $query['sort'],
            $query['capturedAtFrom'],
            $query['capturedAtTo'],
            $query['limit'],
            $query['cursor'],
            $query['tags'],
            $query['tagsMode'],
            $query['hasPreview'],
            $query['locationCountry'],
            $query['locationCity'],
            $query['geoBbox'],
        );

        return $this->responder->listResult($result);
    }

    #[Route('/{uuid}', name: 'api_assets_get', methods: ['GET'])]
    public function getOne(string $uuid): JsonResponse
    {
        return $this->responder->getOneResult($this->assetEndpointsHandler->getOne($uuid));
    }
}
