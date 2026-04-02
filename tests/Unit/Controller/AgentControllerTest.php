<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Api\Service\AgentSignature\AgentPublicKeyRepositoryInterface;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepositoryInterface;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use App\Api\Service\SignedAgentRequestValidator;
use App\Controller\Api\AgentController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AgentControllerTest extends TestCase
{
    use ControllerInstantiationTrait;

    public function testRegisterReturnsUnauthorizedWhenSignatureHeadersAreMissing(): void
    {
        $validator = new SignedAgentRequestValidator(
            $this->createMock(AgentPublicKeyRepositoryInterface::class),
            $this->createMock(AgentRequestSignatureVerifier::class),
            $this->createMock(AgentSignatureNonceRepositoryInterface::class),
            new SignedAgentMessageCanonicalizer(),
            $this->createMock(AgentRuntimeRepositoryInterface::class),
            $this->translator(),
        );

        $controller = $this->controller(AgentController::class, [
            'translator' => $this->translator(),
            'signedAgentRequestValidator' => $validator,
        ]);

        self::assertSame(401, $controller->register(Request::create('/api/v1/agents/register', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}'))->getStatusCode());
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
