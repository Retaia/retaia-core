<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AssetRequestPreconditionService;
use App\Application\Asset\AssetEndpointResult;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\AssetHttpResponder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AssetHttpResponderTest extends TestCase
{
    public function testPatchResultAttachesEtagFromPayload(): void
    {
        $responder = new AssetHttpResponder(
            $this->translator(),
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class))
        );

        $response = $responder->patchResult(new AssetEndpointResult(AssetEndpointResult::STATUS_SUCCESS, [
            'uuid' => 'asset-1',
            'updated_at' => '2026-04-02T12:00:00+00:00',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($response->headers->get('ETag'));
    }

    public function testAssetActionResultReturnsStateConflictPayload(): void
    {
        $responder = new AssetHttpResponder(
            $this->translator(),
            new AssetRequestPreconditionService($this->createStub(AssetRepositoryInterface::class))
        );

        $response = $responder->assetActionResult(new AssetEndpointResult(AssetEndpointResult::STATUS_STATE_CONFLICT));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'code' => 'STATE_CONFLICT',
            'message' => 'asset.error.state_conflict',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
