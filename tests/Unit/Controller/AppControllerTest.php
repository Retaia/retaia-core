<?php

namespace App\Tests\Unit\Controller;

use App\Controller\Api\AppController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AppControllerTest extends TestCase
{    use ControllerInstantiationTrait;

    public function testUpdatePolicyRejectsInvalidFeaturePayload(): void
    {
        $controller = $this->controller(AppController::class, [
            'translator' => $this->translatorStub(),
        ]);

        self::assertSame(422, $controller->updatePolicy(Request::create('/api/v1/app/policy', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"feature_flags":"bad"}'))->getStatusCode());
    }

}
