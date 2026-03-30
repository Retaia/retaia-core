<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\DocsController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

final class DocsControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testDocsDefaultRedirectsAndDocsReturnsNotFoundForMissingVersion(): void
    {
        $twig = $this->createMock(Environment::class);
        $controller = new DocsController(dirname(__DIR__, 3), $twig);

        self::assertSame(302, $controller->docsDefault()->getStatusCode());
        self::assertSame(404, $controller->docs('v999')->getStatusCode());
    }
}
