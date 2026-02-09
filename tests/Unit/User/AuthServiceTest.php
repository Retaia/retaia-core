<?php

namespace App\Tests\Unit\User;

use App\User\Repository\JsonUserRepository;
use App\User\Service\AuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AuthServiceTest extends TestCase
{
    private string $usersFile;
    private RequestStack $requestStack;
    private JsonUserRepository $users;

    protected function setUp(): void
    {
        $tmpDir = sys_get_temp_dir().'/retaia-auth-tests-'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0775, true);
        $this->usersFile = $tmpDir.'/users.json';
        $this->users = new JsonUserRepository($this->usersFile);

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

