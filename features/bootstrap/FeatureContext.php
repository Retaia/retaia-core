<?php

use App\Tests\Support\InMemoryUserRepository;
use App\User\Service\AuthService;
use App\User\Service\PasswordResetService;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class FeatureContext implements Context
{
    private string $tmpDir;
    private InMemoryUserRepository $users;
    private RequestStack $requestStack;
    private AuthService $authService;
    private PasswordResetService $passwordResetService;
    private bool $lastLoginSucceeded = false;
    private ?string $lastToken = null;

    /**
     * @Given a bootstrap user exists
     */
    public function aBootstrapUserExists(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/retaia-behat-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0775, true);
        $this->users = new InMemoryUserRepository();
        $this->users->seedDefaultAdmin();

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);

        $this->authService = new AuthService($this->users, $this->requestStack);
        $this->passwordResetService = new PasswordResetService($this->users, $this->tmpDir.'/tokens.json', 'test');
    }

    /**
     * @When I login with email :email and password :password
     */
    public function iLoginWithCredentials(string $email, string $password): void
    {
        $this->lastLoginSucceeded = $this->authService->login($email, $password);
    }

    /**
     * @Then authentication should succeed
     */
    public function authenticationShouldSucceed(): void
    {
        Assert::assertTrue($this->lastLoginSucceeded);
        Assert::assertNotNull($this->authService->currentUser());
    }

    /**
     * @When I request a password reset for :email
     */
    public function iRequestAPasswordResetFor(string $email): void
    {
        $this->lastToken = $this->passwordResetService->requestReset($email);
    }

    /**
     * @When I reset the password to :newPassword using the reset token
     */
    public function iResetThePasswordUsingTheResetToken(string $newPassword): void
    {
        Assert::assertIsString($this->lastToken);
        Assert::assertTrue($this->passwordResetService->resetPassword((string) $this->lastToken, $newPassword));
    }

    /**
     * @Then I can login with email :email and password :password
     */
    public function iCanLoginWithEmailAndPassword(string $email, string $password): void
    {
        $this->authService->logout();
        Assert::assertTrue($this->authService->login($email, $password));
    }
}
