<?php

namespace App\Tests\Unit\Ingest\Service;

use App\Ingest\Service\SidecarDetectionRules;
use PHPUnit\Framework\TestCase;

final class SidecarDetectionRulesTest extends TestCase
{
    public function testExistingAuxiliaryExtensionsRespectLegacyFlag(): void
    {
        $disabled = new SidecarDetectionRules(false);
        $enabled = new SidecarDetectionRules(true);

        self::assertSame(['xmp', 'srt'], $disabled->existingAuxiliaryExtensionsForOriginal('mp4'));
        self::assertSame(['xmp', 'srt', 'lrv', 'thm'], $enabled->existingAuxiliaryExtensionsForOriginal('mp4'));
    }

    public function testAuxiliaryAndProxyRulesExposeExpectedSemantics(): void
    {
        $rules = new SidecarDetectionRules();

        self::assertTrue($rules->isAuxiliarySidecarExtension('xmp'));
        self::assertFalse($rules->isAttachableAuxiliarySidecarExtension('lrv'));
        self::assertSame('proxy_video', $rules->proxyKindForExtension('lrf'));
        self::assertSame('proxy_photo', $rules->proxyKindForExtension('jpg'));
        self::assertSame('proxy_audio', $rules->proxyKindForExtension('wav'));
        self::assertNull($rules->proxyKindForExtension('txt'));
    }
}
