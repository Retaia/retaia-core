<?php

namespace App\Tests\Support;

use Symfony\Contracts\Translation\TranslatorInterface;

trait TranslatorStubTrait
{
    private function translatorStub(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}