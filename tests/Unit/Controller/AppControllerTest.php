<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AppController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AppControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testUpdatePolicyRejectsInvalidFeaturePayload(): void
    {
        $controller = $this->controller(AppController::class, [
            'translator' => $this->translator(),
        ]);

        self::assertSame(422, $controller->updatePolicy(Request::create('/api/v1/app/policy', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"feature_flags":"bad"}'))->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
