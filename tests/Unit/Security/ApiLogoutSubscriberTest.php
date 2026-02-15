<?php

namespace App\Tests\Unit\Security;

use App\Auth\UserAccessTokenService;
use App\Security\ApiLogoutSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiLogoutSubscriberTest extends TestCase
{
    public function testOnLogoutSkipsNonApiLogoutRoute(): void
    {
        $subscriber = new ApiLogoutSubscriber(new NullLogger(), new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600));
        $request = Request::create('/logout', Request::METHOD_POST);
        $request->attributes->set('_route', 'other_route');

        $event = new LogoutEvent($request, null);
        $subscriber->onLogout($event);

        self::assertNull($event->getResponse());
    }

    public function testOnLogoutSetsApiResponseOnLogoutRoute(): void
    {
        $subscriber = new ApiLogoutSubscriber(new NullLogger(), new UserAccessTokenService(new ArrayAdapter(), 'test-secret', 3600));
        $request = Request::create('/api/v1/auth/logout', Request::METHOD_POST);
        $request->attributes->set('_route', 'api_auth_logout');

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('user-1');

        $event = new LogoutEvent($request, $token);
        $subscriber->onLogout($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(200, $event->getResponse()?->getStatusCode());
        self::assertSame(false, json_decode((string) $event->getResponse()?->getContent(), true)['authenticated']);
    }
}
