<?php

namespace App\Tests\Unit\Application\AuthClient;

use App\Application\Auth\Port\AdminActorGateway;
use App\Application\Auth\Port\AuthenticatedUserGateway;
use App\Application\Auth\ResolveAdminActorHandler;
use App\Application\Auth\ResolveAuthenticatedUserHandler;
use App\Application\AuthClient\AuthClientAdminEndpointsHandler;
use App\Application\AuthClient\RevokeClientTokenEndpointResult;
use App\Application\AuthClient\RevokeClientTokenHandler;
use App\Application\AuthClient\RotateClientSecretEndpointResult;
use App\Application\AuthClient\RotateClientSecretHandler;
use App\Application\AuthClient\Port\AuthClientGateway;
use App\Domain\AuthClient\TechnicalClientAdminPolicy;
use PHPUnit\Framework\TestCase;

final class AuthClientAdminEndpointsHandlerTest extends TestCase
{
    public function testRevokeReturnsUnauthorizedWhenUserIsNotAuthenticated(): void
    {
        $authGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authGateway->expects(self::once())->method('currentUser')->willReturn(null);

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::never())->method('isAdmin');

        $clientGateway = $this->createMock(AuthClientGateway::class);
        $clientGateway->expects(self::never())->method('hasClient');

        $handler = new AuthClientAdminEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authGateway),
            new ResolveAdminActorHandler($adminGateway),
            new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $clientGateway),
            new RotateClientSecretHandler($clientGateway),
        );

        $result = $handler->revoke('agent-default');

        self::assertSame(RevokeClientTokenEndpointResult::STATUS_UNAUTHORIZED, $result->status());
    }

    public function testRevokeReturnsForbiddenActorWhenAdminActorIsMissing(): void
    {
        $authGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authGateway->expects(self::once())->method('currentUser')->willReturn([
            'id' => 'u1',
            'email' => 'admin@retaia.local',
            'roles' => ['ROLE_USER'],
        ]);

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::once())->method('isAdmin')->willReturn(false);

        $clientGateway = $this->createMock(AuthClientGateway::class);
        $clientGateway->expects(self::never())->method('hasClient');

        $handler = new AuthClientAdminEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authGateway),
            new ResolveAdminActorHandler($adminGateway),
            new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $clientGateway),
            new RotateClientSecretHandler($clientGateway),
        );

        $result = $handler->revoke('agent-default');

        self::assertSame(RevokeClientTokenEndpointResult::STATUS_FORBIDDEN_ACTOR, $result->status());
    }

    public function testRevokeReturnsSuccess(): void
    {
        $authGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authGateway->expects(self::once())->method('currentUser')->willReturn([
            'id' => 'u1',
            'email' => 'admin@retaia.local',
            'roles' => ['ROLE_ADMIN'],
        ]);

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::once())->method('isAdmin')->willReturn(true);
        $adminGateway->expects(self::once())->method('actorId')->willReturn('u1');

        $clientGateway = $this->createMock(AuthClientGateway::class);
        $clientGateway->expects(self::once())->method('hasClient')->with('agent-default')->willReturn(true);
        $clientGateway->expects(self::once())->method('clientKind')->with('agent-default')->willReturn('AGENT');
        $clientGateway->expects(self::once())->method('revokeToken')->with('agent-default')->willReturn(true);

        $handler = new AuthClientAdminEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authGateway),
            new ResolveAdminActorHandler($adminGateway),
            new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $clientGateway),
            new RotateClientSecretHandler($clientGateway),
        );

        $result = $handler->revoke('agent-default');

        self::assertSame(RevokeClientTokenEndpointResult::STATUS_SUCCESS, $result->status());
        self::assertSame('AGENT', $result->clientKind());
    }

    public function testRotateReturnsValidationFailedWhenClientUnknown(): void
    {
        $authGateway = $this->createMock(AuthenticatedUserGateway::class);
        $authGateway->expects(self::once())->method('currentUser')->willReturn([
            'id' => 'u1',
            'email' => 'admin@retaia.local',
            'roles' => ['ROLE_ADMIN'],
        ]);

        $adminGateway = $this->createMock(AdminActorGateway::class);
        $adminGateway->expects(self::once())->method('isAdmin')->willReturn(true);

        $clientGateway = $this->createMock(AuthClientGateway::class);
        $clientGateway->expects(self::once())->method('rotateSecret')->with('missing')->willReturn(null);

        $handler = new AuthClientAdminEndpointsHandler(
            new ResolveAuthenticatedUserHandler($authGateway),
            new ResolveAdminActorHandler($adminGateway),
            new RevokeClientTokenHandler(new TechnicalClientAdminPolicy(), $clientGateway),
            new RotateClientSecretHandler($clientGateway),
        );

        $result = $handler->rotate('missing');

        self::assertSame(RotateClientSecretEndpointResult::STATUS_VALIDATION_FAILED, $result->status());
    }
}
