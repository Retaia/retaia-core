<?php

use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Tests\Support\InMemoryUserRepository;
use App\Tests\Support\InMemoryPasswordResetTokenRepository;
use App\Tests\Support\TestUserPasswordHasher;
use App\User\Service\AuthService;
use App\User\Service\EmailVerificationService;
use App\User\Service\PasswordResetService;
use Behat\Behat\Context\Context;
use Psr\Log\NullLogger;
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
    private EmailVerificationService $emailVerificationService;
    private InMemoryPasswordResetTokenRepository $tokenRepository;
    private bool $lastLoginSucceeded = false;
    private bool $lastPasswordResetSucceeded = false;
    private bool $lastEmailVerificationSucceeded = false;
    private ?string $lastToken = null;
    private ?string $lastVerificationToken = null;
    private ?Asset $asset = null;
    private bool $lastTransitionSucceeded = false;

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
        $this->tokenRepository = new InMemoryPasswordResetTokenRepository();
        $this->passwordResetService = new PasswordResetService(
            $this->users,
            $this->tokenRepository,
            new TestUserPasswordHasher(),
            new NullLogger(),
            'test',
            3600,
        );
        $this->emailVerificationService = new EmailVerificationService(
            $this->users,
            new NullLogger(),
            'test',
            'behat-secret',
            3600,
        );
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
     * @Then authentication should fail
     */
    public function authenticationShouldFail(): void
    {
        Assert::assertFalse($this->lastLoginSucceeded);
        Assert::assertNull($this->authService->currentUser());
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
        $this->lastPasswordResetSucceeded = $this->passwordResetService->resetPassword((string) $this->lastToken, $newPassword);
        Assert::assertTrue($this->lastPasswordResetSucceeded);
    }

    /**
     * @When the reset token has expired
     */
    public function theResetTokenHasExpired(): void
    {
        Assert::assertIsString($this->lastToken);
        $this->tokenRepository->forceExpire((string) $this->lastToken);
    }

    /**
     * @Then the password reset should be rejected for :newPassword
     */
    public function thePasswordResetShouldBeRejectedFor(string $newPassword): void
    {
        Assert::assertIsString($this->lastToken);
        $this->lastPasswordResetSucceeded = $this->passwordResetService->resetPassword((string) $this->lastToken, $newPassword);
        Assert::assertFalse($this->lastPasswordResetSucceeded);
    }

    /**
     * @When I try to reset the password to :newPassword with token :token
     */
    public function iTryToResetThePasswordWithToken(string $newPassword, string $token): void
    {
        $this->lastPasswordResetSucceeded = $this->passwordResetService->resetPassword($token, $newPassword);
    }

    /**
     * @Then the password reset should fail
     */
    public function thePasswordResetShouldFail(): void
    {
        Assert::assertFalse($this->lastPasswordResetSucceeded);
    }

    /**
     * @Then I can login with email :email and password :password
     */
    public function iCanLoginWithEmailAndPassword(string $email, string $password): void
    {
        $this->authService->logout();
        Assert::assertTrue($this->authService->login($email, $password));
    }

    /**
     * @Given an unverified user exists with email :email and password :password
     */
    public function anUnverifiedUserExistsWithEmailAndPassword(string $email, string $password): void
    {
        $this->aBootstrapUserExists();
        $this->users->seedUnverifiedUser($email, $password);
    }

    /**
     * @When I request an email verification for :email
     */
    public function iRequestAnEmailVerificationFor(string $email): void
    {
        $this->lastVerificationToken = $this->emailVerificationService->requestVerification($email);
    }

    /**
     * @When I confirm the email verification token
     */
    public function iConfirmTheEmailVerificationToken(): void
    {
        Assert::assertIsString($this->lastVerificationToken);
        $this->lastEmailVerificationSucceeded = $this->emailVerificationService->confirmVerification((string) $this->lastVerificationToken);
        Assert::assertTrue($this->lastEmailVerificationSucceeded);
    }

    /**
     * @When I try to verify email with token :token
     */
    public function iTryToVerifyEmailWithToken(string $token): void
    {
        $this->lastEmailVerificationSucceeded = $this->emailVerificationService->confirmVerification($token);
    }

    /**
     * @Then email verification should fail
     */
    public function emailVerificationShouldFail(): void
    {
        Assert::assertFalse($this->lastEmailVerificationSucceeded);
    }

    /**
     * @Given an asset exists in state :state
     */
    public function anAssetExistsInState(string $state): void
    {
        $this->asset = new Asset(
            'behat-asset-00000000-0000-0000-0000',
            'VIDEO',
            'behat.mov',
            AssetState::from($state)
        );
        $this->lastTransitionSucceeded = false;
    }

    /**
     * @When I apply decision action :action
     */
    public function iApplyDecisionAction(string $action): void
    {
        Assert::assertInstanceOf(Asset::class, $this->asset);
        $stateMachine = new AssetStateMachine();

        try {
            $stateMachine->decide($this->asset, $action);
            $this->lastTransitionSucceeded = true;
        } catch (StateConflictException) {
            $this->lastTransitionSucceeded = false;
        }
    }

    /**
     * @Then the asset state should be :state
     */
    public function theAssetStateShouldBe(string $state): void
    {
        Assert::assertInstanceOf(Asset::class, $this->asset);
        Assert::assertSame($state, $this->asset->getState()->value);
        Assert::assertTrue($this->lastTransitionSucceeded);
    }

    /**
     * @Then the decision transition should be rejected
     */
    public function theDecisionTransitionShouldBeRejected(): void
    {
        Assert::assertFalse($this->lastTransitionSucceeded);
    }
}
