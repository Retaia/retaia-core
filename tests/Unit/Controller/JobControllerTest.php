<?php

namespace App\Tests\Unit\Controller;

use App\Api\Service\AgentRuntimeRepositoryInterface;
use App\Api\Service\AgentSignature\AgentPublicKeyRepositoryInterface;
use App\Api\Service\AgentSignature\AgentRequestSignatureVerifier;
use App\Api\Service\AgentSignature\AgentSignatureNonceRepositoryInterface;
use App\Api\Service\AgentSignature\SignedAgentMessageCanonicalizer;
use App\Api\Service\SignedAgentRequestValidator;
use App\Controller\Api\JobController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class JobControllerTest extends TestCase
{    use ControllerInstantiationTrait;

    public function testClaimRejectsUnsignedRequests(): void
    {
        $validator = new SignedAgentRequestValidator(
            $this->createMock(AgentPublicKeyRepositoryInterface::class),
            $this->createMock(AgentRequestSignatureVerifier::class),
            $this->createMock(AgentSignatureNonceRepositoryInterface::class),
            new SignedAgentMessageCanonicalizer(),
            $this->createMock(AgentRuntimeRepositoryInterface::class),
            $this->translatorStub(),
        );

        $controller = $this->controller(JobController::class, [
            'logger' => $this->createMock(LoggerInterface::class),
            'translator' => $this->translatorStub(),
            'signedAgentRequestValidator' => $validator,
            'agentRuntimeRepository' => $this->createMock(AgentRuntimeRepositoryInterface::class),
        ]);

        self::assertSame(401, $controller->claim('job-1', Request::create('/api/v1/jobs/job-1/claim', 'POST'))->getStatusCode());
    }

}
