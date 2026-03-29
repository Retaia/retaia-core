<?php

namespace App\Tests\Unit\Derived;

use App\Derived\DerivedUploadSessionRepository;
use App\Tests\Support\DerivedUploadSessionEntityManagerTrait;
use PHPUnit\Framework\TestCase;

final class DerivedUploadSessionRepositoryTest extends TestCase
{
    use DerivedUploadSessionEntityManagerTrait;

    public function testCreateFindUpdateAndCompleteSession(): void
    {
        $repository = new DerivedUploadSessionRepository($this->derivedUploadSessionEntityManager());

        $created = $repository->create('asset-1', 'proxy', 'video/mp4', 123, 'hash');
        self::assertSame('asset-1', $created->assetUuid);
        self::assertTrue($created->isOpen());
        self::assertSame(0, $created->partsCount);

        $repository->updateHighestPartCount($created->uploadId, 3);
        $repository->updateHighestPartCount($created->uploadId, 2);
        $updated = $repository->find($created->uploadId);
        self::assertNotNull($updated);
        self::assertSame(3, $updated->partsCount);

        $repository->markCompleted($created->uploadId);
        $completed = $repository->find($created->uploadId);
        self::assertNotNull($completed);
        self::assertFalse($completed->isOpen());
    }
}
