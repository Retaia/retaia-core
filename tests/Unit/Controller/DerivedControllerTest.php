<?php

namespace App\Tests\Unit\Controller;

use App\Asset\AssetState;
use App\Asset\Repository\AssetRepositoryInterface;
use App\Controller\Api\DerivedController;
use App\Derived\Service\DerivedUploadService;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DerivedControllerTest extends TestCase
{
    public function testInitUploadForbiddenNotFoundAndValidation(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $translator = $this->translator();
        $service = new DerivedUploadService($this->createMock(Connection::class));

        $securityForbidden = $this->createMock(Security::class);
        $securityForbidden->method('isGranted')->with('ROLE_AGENT')->willReturn(false);
        $controller = new DerivedController($assets, $service, $securityForbidden, $translator);
        self::assertSame(Response::HTTP_FORBIDDEN, $controller->initUpload('a1', Request::create('/x', 'POST'))->getStatusCode());

        $securityAgent = $this->createMock(Security::class);
        $securityAgent->method('isGranted')->with('ROLE_AGENT')->willReturn(true);
        $assets->method('findByUuid')->willReturnOnConsecutiveCalls(null, $this->asset('a3'));
        $controller = new DerivedController($assets, $service, $securityAgent, $translator);
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->initUpload('a2', Request::create('/x', 'POST'))->getStatusCode());

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->initUpload('a3', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
        );
    }

    public function testUploadPartAndCompleteUploadValidationAndConflictBranches(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $assets->method('findByUuid')->willReturn($this->asset('a4'));
        $translator = $this->translator();
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->with('ROLE_AGENT')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['upload_id' => 'up-1', 'status' => 'completed']);
        $service = new DerivedUploadService($connection);

        $controller = new DerivedController($assets, $service, $security, $translator);
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->uploadPart('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->uploadPart('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"upload_id":"up","part_number":1}'))->getStatusCode()
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $controller->completeUpload('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode()
        );
        self::assertSame(
            Response::HTTP_CONFLICT,
            $controller->completeUpload('a4', Request::create('/x', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"upload_id":"up","total_parts":1}'))->getStatusCode()
        );
    }

    public function testListDerivedAndGetByKindBranches(): void
    {
        $assets = $this->createMock(AssetRepositoryInterface::class);
        $translator = $this->translator();
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(false);
        $service = new DerivedUploadService($connection);

        $controller = new DerivedController($assets, $service, $security, $translator);

        $assets->method('findByUuid')->willReturnOnConsecutiveCalls(null, null, $this->asset('a2'), $this->asset('a2'));
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->listDerived('a1')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->getByKind('a1', 'proxy')->getStatusCode());

        self::assertSame(Response::HTTP_OK, $controller->listDerived('a2')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $controller->getByKind('a2', 'proxy')->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function asset(string $uuid): Asset
    {
        return new Asset(
            uuid: $uuid,
            mediaType: 'video',
            filename: 'file.mp4',
            state: AssetState::READY,
            tags: [],
            notes: null,
            fields: [],
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: new \DateTimeImmutable('-1 hour'),
        );
    }
}
