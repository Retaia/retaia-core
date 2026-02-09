<?php

namespace App\Tests\Unit\User;

use App\Tests\Support\InMemoryUserRepository;
use App\User\Service\AuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AuthServiceTest extends TestCase
{
    private RequestStack $requestStack;
    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->users->seedDefaultAdmin();

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);
    }

    public function testLoginSucceedsWithDefaultBootstrapUser(): void
    {
        $service = new AuthService($this->users, $this->requestStack);

        self::assertTrue($service->login('admin@retaia.local', 'change-me'));
        self::assertNotNull($service->currentUser());
    }

    public function testLoginFailsWithWrongPassword(): void
    {
        $service = new AuthService($this->users, $this->requestStack);

        self::assertFalse($service->login('admin@retaia.local', 'wrong-password'));
        self::assertNull($service->currentUser());
    }

    public function testLogoutRemovesSessionAuthentication(): void
    {
        $service = new AuthService($this->users, $this->requestStack);

        self::assertTrue($service->login('admin@retaia.local', 'change-me'));
        self::assertNotNull($service->currentUser());
        $service->logout();

        self::assertNull($service->currentUser());
    }
}
