<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\BusinessStorageAwareSidecarLocator;
use App\Ingest\Service\SidecarFileDetector;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageInterface;
use App\Storage\BusinessStorageRegistryInterface;
use PHPUnit\Framework\TestCase;

final class BusinessStorageAwareSidecarLocatorTest extends TestCase
{
    public function testDelegatesDetectionAndFiltersUnsafeSidecars(): void
    {
        $storage = $this->createMock(BusinessStorageInterface::class);
        $registry = $this->createMock(BusinessStorageRegistryInterface::class);
        $registry->method('get')->with('nas-main')->willReturn(new BusinessStorageDefinition('nas-main', $storage));

        $detector = $this->createMock(SidecarFileDetector::class);
        $detector->expects(self::once())->method('detectProxyFile')->with('INBOX/file.mov', self::isCallable())->willReturn(['path' => 'INBOX/file.lrf']);
        $detector->expects(self::once())->method('detectExistingAuxiliarySidecarsForOriginal')->with('INBOX/file.mov', self::isCallable())->willReturn(['good.srt', '../bad.srt', '/abs.srt']);

        $locator = new BusinessStorageAwareSidecarLocator($registry, $detector);

        self::assertSame(['path' => 'INBOX/file.lrf'], $locator->detectProxyFile('nas-main', 'INBOX/file.mov'));
        self::assertSame(['good.srt'], $locator->existingAuxiliarySidecarsForOriginal('nas-main', 'INBOX/file.mov'));
    }
}
