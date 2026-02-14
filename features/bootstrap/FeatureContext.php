<?php

use App\Asset\AssetState;
use App\Asset\Service\AssetStateMachine;
use App\Asset\Service\StateConflictException;
use App\Entity\Asset;
use App\Tests\Support\InMemoryUserRepository;
use App\Tests\Support\InMemoryPasswordResetTokenRepository;
use App\Tests\Support\TestUserPasswordHasher;
use App\Feature\FeatureGovernanceService;
use App\User\Service\AuthService;
use App\User\Service\EmailVerificationService;
use App\User\Service\PasswordResetService;
use Behat\Behat\Context\Context;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

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
    /** @var array<string, array<int, string>>|null */
    private ?array $lastFeaturePayloadValidation = null;
    /** @var array<string, bool> */
    private array $pendingJobs = [];
    /** @var array<string, string> */
    private array $claimedJobs = [];
    /** @var array<string, bool> */
    private array $claimResults = [];

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
            $this->buildVerifyEmailHelper(3600),
            'test',
        );
    }

    private function buildVerifyEmailHelper(int $lifetimeSeconds): VerifyEmailHelperInterface
    {
        return new class($lifetimeSeconds) implements VerifyEmailHelperInterface {
            public function __construct(private int $lifetimeSeconds)
            {
            }

            public function generateSignature(string $routeName, string $userId, string $userEmail, array $extraParams = []): VerifyEmailSignatureComponents
            {
                $expires = time() + $this->lifetimeSeconds;
                $normalizedEmail = mb_strtolower(trim($userEmail));
                $signature = hash('sha256', $userId.'|'.$normalizedEmail.'|'.$expires);
                $id = (string) ($extraParams['id'] ?? $userId);
                $url = sprintf(
                    'http://localhost/api/v1/auth/verify-email/confirm?id=%s&expires=%d&signature=%s&email=%s',
                    rawurlencode($id),
                    $expires,
                    rawurlencode($signature),
                    rawurlencode($normalizedEmail)
                );

                return new VerifyEmailSignatureComponents(
                    (new \DateTimeImmutable())->setTimestamp($expires),
                    $url,
                    time()
                );
            }

            public function validateEmailConfirmation(string $signedUrl, string $userId, string $userEmail): void
            {
                $parts = parse_url($signedUrl);
                $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
                parse_str($query, $params);

                $id = (string) ($params['id'] ?? '');
                $expires = (int) ($params['expires'] ?? 0);
                $signature = (string) ($params['signature'] ?? '');
                $email = mb_strtolower((string) ($params['email'] ?? ''));
                $normalizedEmail = mb_strtolower(trim($userEmail));
                $expected = hash('sha256', $userId.'|'.$normalizedEmail.'|'.$expires);

                if ($id !== $userId || $email !== $normalizedEmail || $expires < time() || !hash_equals($expected, $signature)) {
                    throw new InvalidSignatureException();
                }
            }

            public function validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail): void
            {
                $this->validateEmailConfirmation($request->getUri(), $userId, $userEmail);
            }
        };
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

    /**
     * @Given a pending job exists with id :jobId
     */
    public function aPendingJobExistsWithId(string $jobId): void
    {
        $this->pendingJobs[$jobId] = true;
        unset($this->claimedJobs[$jobId]);
        $this->claimResults = [];
    }

    /**
     * @When agent :agentId claims job :jobId
     */
    public function agentClaimsJob(string $agentId, string $jobId): void
    {
        $canClaim = ($this->pendingJobs[$jobId] ?? false) && !isset($this->claimedJobs[$jobId]);
        if ($canClaim) {
            $this->claimedJobs[$jobId] = $agentId;
        }

        $this->claimResults[$agentId] = $canClaim;
    }

    /**
     * @Then exactly one claim should succeed
     */
    public function exactlyOneClaimShouldSucceed(): void
    {
        $successfulClaims = array_values(array_filter($this->claimResults, static fn (bool $result): bool => $result));
        Assert::assertCount(1, $successfulClaims);
    }

    /**
     * @When I validate app feature payload with unknown key
     */
    public function iValidateAppFeaturePayloadWithUnknownKey(): void
    {
        $governance = new FeatureGovernanceService(new ArrayAdapter(), false, false, false);
        $this->lastFeaturePayloadValidation = $governance->validateFeaturePayload(
            ['features.unknown.flag' => true],
            $governance->allowedAppFeatureKeys()
        );
    }

    /**
     * @When I validate app feature payload with non-boolean value
     */
    public function iValidateAppFeaturePayloadWithNonBooleanValue(): void
    {
        $governance = new FeatureGovernanceService(new ArrayAdapter(), false, false, false);
        $this->lastFeaturePayloadValidation = $governance->validateFeaturePayload(
            ['features.ai' => 'disabled'],
            $governance->allowedAppFeatureKeys()
        );
    }

    /**
     * @Then unknown feature keys should contain :key
     */
    public function unknownFeatureKeysShouldContain(string $key): void
    {
        Assert::assertIsArray($this->lastFeaturePayloadValidation);
        Assert::assertContains($key, $this->lastFeaturePayloadValidation['unknown_keys'] ?? []);
    }

    /**
     * @Then non-boolean feature keys should contain :key
     */
    public function nonBooleanFeatureKeysShouldContain(string $key): void
    {
        Assert::assertIsArray($this->lastFeaturePayloadValidation);
        Assert::assertContains($key, $this->lastFeaturePayloadValidation['non_boolean_keys'] ?? []);
    }
}
