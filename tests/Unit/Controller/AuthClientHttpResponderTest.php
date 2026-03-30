<?php

namespace App\Tests\Unit\Controller;

use App\Application\AuthClient\MintClientTokenEndpointResult;
use App\Application\AuthClient\PollDeviceFlowEndpointResult;
use App\Controller\Api\AuthApiErrorResponder;
use App\Controller\Api\AuthClientHttpResponder;
use App\Observability\MetricName;
use App\Observability\Repository\MetricEventRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AuthClientHttpResponderTest extends TestCase
{
    public function testClientTokenForbiddenActorRecordsMetric(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('ops_metric_event', self::callback(static fn (array $values): bool => ($values['metric_key'] ?? null) === MetricName::AUTH_CLIENT_TOKEN_FORBIDDEN_ACTOR_UI_WEB));

        $metrics = new MetricEventRepository($connection);
        $logger = $this->createStub(LoggerInterface::class);
        $responder = new AuthClientHttpResponder(new AuthApiErrorResponder($this->translator()), $metrics, $logger);

        $response = $responder->clientToken(new MintClientTokenEndpointResult(MintClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([
            'code' => 'FORBIDDEN_ACTOR',
            'message' => 'auth.error.forbidden_actor',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testPollDeviceFlowApprovedRecordsStatusMetricAndLogs(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('ops_metric_event', self::callback(static fn (array $values): bool => ($values['metric_key'] ?? null) === MetricName::authDevicePollStatus('APPROVED')));

        $metrics = new MetricEventRepository($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('auth.device.approved');

        $responder = new AuthClientHttpResponder(new AuthApiErrorResponder($this->translator()), $metrics, $logger);
        $response = $responder->pollDeviceFlow(new PollDeviceFlowEndpointResult(
            PollDeviceFlowEndpointResult::STATUS_SUCCESS,
            ['status' => 'approved', 'client_id' => 'c1'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'status' => 'approved',
            'client_id' => 'c1',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }
}
