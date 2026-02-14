<?php

namespace App\Tests\Functional;

use App\Tests\Support\FixtureUsers;
use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthFlowTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testLoginAndMeFlowWithSession(): void
    {
        $client = $this->createIsolatedClient('10.0.0.11');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('content-type', 'application/json');
        $loginPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($loginPayload);
        self::assertTrue((bool) ($loginPayload['authenticated'] ?? false));
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $loginPayload['user']['email'] ?? null);

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $mePayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $mePayload['email'] ?? null);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $client = $this->createIsolatedClient('10.0.0.12');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $logoutPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($logoutPayload);
        self::assertFalse((bool) ($logoutPayload['authenticated'] ?? true));

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLostPasswordResetFlow(): void
    {
        $client = $this->createIsolatedClient('10.0.0.13');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        self::assertSame(true, $requestPayload['accepted'] ?? null);

        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testLostPasswordRejectsWeakPassword(): void
    {
        $client = $this->createIsolatedClient('10.0.0.14');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testLostPasswordRequestIsRateLimited(): void
    {
        $email = sprintf('reset-limit-%s@retaia.local', bin2hex(random_bytes(6)));
        $client = $this->createIsolatedClient(sprintf('10.0.%d.%d', random_int(1, 200), random_int(1, 200)));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
                'email' => $email,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_seconds'] ?? 0));
    }

    public function testLoginThrottlingReturns429AfterTooManyFailures(): void
    {
        $client = $this->createIsolatedClient(sprintf('10.0.%d.%d', random_int(1, 200), random_int(1, 200)));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->jsonRequest('POST', '/api/v1/auth/login', [
                'email' => 'admin@retaia.local',
                'password' => 'invalid-password',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'invalid-password',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_minutes'] ?? 0));
    }

    public function testLostPasswordResetFailsWhenTokenExpired(): void
    {
        $client = $this->createIsolatedClient('10.0.0.16');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $this->forceTokenExpired($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'New-password1!',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testLostPasswordResetTokenCannotBeReused(): void
    {
        $client = $this->createIsolatedClient('10.0.0.37');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'New-password1!',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'Another-password1!',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testLogoutWithoutAuthenticationReturns401(): void
    {
        $client = $this->createIsolatedClient('10.0.0.17');

        $client->jsonRequest('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    private function forceTokenExpired(string $token): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE password_reset_token SET expires_at = :expiresAt WHERE token_hash = :tokenHash',
            [
                'expiresAt' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
                'tokenHash' => hash('sha256', $token),
            ],
        );
    }

    public function testLoginFailsWhenEmailIsNotVerified(): void
    {
        $client = $this->createIsolatedClient('10.0.0.16');
        $email = sprintf('pending-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], false);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('EMAIL_NOT_VERIFIED', $payload['code'] ?? null);
    }

    public function testLostPasswordRejectsPasswordWithoutSpecialCharacter(): void
    {
        $client = $this->createIsolatedClient('10.0.0.17');

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
            'email' => 'admin@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['reset_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/lost-password/reset', [
            'token' => $token,
            'new_password' => 'NoSpecial1234',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame('new_password must include at least one special character', $payload['message'] ?? null);
    }

    public function testEmailVerificationFlowEnablesLogin(): void
    {
        $client = $this->createIsolatedClient('10.0.0.18');
        $email = sprintf('verify-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], false);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['verification_token'] ?? null;
        self::assertIsString($token);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => $token,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $confirmPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(true, $confirmPayload['email_verified'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testEmailVerificationConfirmRejectsInvalidToken(): void
    {
        $client = $this->createIsolatedClient('10.0.0.19');

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testAdminCanForceVerifyUserEmail(): void
    {
        $client = $this->createIsolatedClient('10.0.0.20');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/admin-confirm', [
            'email' => 'pending@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(true, $payload['email_verified'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'pending@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testVerifyEmailRequestIsRateLimited(): void
    {
        $client = $this->createIsolatedClient('10.0.0.21');
        $email = sprintf('rate-limit-%s@retaia.local', bin2hex(random_bytes(6)));

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
                'email' => $email,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
            'email' => $email,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_seconds'] ?? 0));
    }

    public function testEmailVerificationConfirmRejectsTamperedToken(): void
    {
        $client = $this->createIsolatedClient('10.0.0.22');

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/request', [
            'email' => 'pending@retaia.local',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $requestPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($requestPayload);
        $token = $requestPayload['verification_token'] ?? null;
        self::assertIsString($token);

        $parts = parse_url($token);
        self::assertIsArray($parts);
        $query = $parts['query'] ?? '';
        self::assertIsString($query);

        parse_str($query, $params);
        self::assertIsArray($params);
        self::assertIsString($params['signature'] ?? null);
        $params['signature'] = 'X'.substr((string) $params['signature'], 1);

        $path = (string) ($parts['path'] ?? '/');
        $base = sprintf(
            '%s://%s%s',
            (string) ($parts['scheme'] ?? 'http'),
            (string) ($parts['host'] ?? 'localhost'),
            $path
        );
        $tamperedToken = $base.'?'.http_build_query($params);

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => $tamperedToken,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
    }

    public function testFrenchLocaleReturnsTranslatedAuthMessage(): void
    {
        $client = $this->createIsolatedClient('10.0.0.30', 'fr');

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
        self::assertSame('Token invalide ou expiré', $payload['message'] ?? null);
    }

    public function testUnsupportedLocaleFallsBackToEnglishMessage(): void
    {
        $client = $this->createIsolatedClient('10.0.0.31', 'de');

        $client->jsonRequest('POST', '/api/v1/auth/verify-email/confirm', [
            'token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_TOKEN', $payload['code'] ?? null);
        self::assertSame('Token invalid or expired', $payload['message'] ?? null);
    }

    public function testFrenchLocaleTranslatesAuthenticationRequiredMessage(): void
    {
        $client = $this->createIsolatedClient('10.0.0.34', 'fr');

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
        self::assertSame('Authentification requise', $payload['message'] ?? null);
    }

    public function testTwoFactorSetupRequiresAuthentication(): void
    {
        $client = $this->createIsolatedClient('10.0.0.50');

        $client->jsonRequest('POST', '/api/v1/auth/2fa/setup');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testTwoFactorSetupReturnsProvisioningMaterial(): void
    {
        $client = $this->createIsolatedClient('10.0.0.51');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertMatchesRegularExpression('/^[A-Z2-7]+=*$/', (string) ($payload['secret'] ?? ''));
        self::assertStringStartsWith('otpauth://totp/', (string) ($payload['otpauth_uri'] ?? ''));
    }

    public function testTwoFactorEnableAndDisableFlow(): void
    {
        $client = $this->createIsolatedClient('10.0.0.52');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $setupPayload = json_decode($client->getResponse()->getContent(), true);
        $secret = (string) ($setupPayload['secret'] ?? '');
        $otpCode = $this->generateOtpCode($secret);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/enable', ['otp_code' => $otpCode]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('MFA_REQUIRED', $payload['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
            'otp_code' => '000000',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_2FA_CODE', $payload['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
            'otp_code' => $otpCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/disable', ['otp_code' => $otpCode]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testTwoFactorEnableRejectsInvalidOtpCode(): void
    {
        $client = $this->createIsolatedClient('10.0.0.53');
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/enable', ['otp_code' => '000000']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_2FA_CODE', $payload['code'] ?? null);
    }

    public function testTwoFactorLoginAcceptsSlightClockDriftOtpCode(): void
    {
        $client = $this->createIsolatedClient('10.0.0.531');
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $setupPayload = json_decode($client->getResponse()->getContent(), true);
        $secret = (string) ($setupPayload['secret'] ?? '');
        $currentOtpCode = $this->generateOtpCode($secret);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/enable', ['otp_code' => $currentOtpCode]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/logout');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $driftedCode = $this->generateOtpCodeAt($secret, time() - 20);
        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
            'otp_code' => $driftedCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/2fa/disable', [
            'otp_code' => $this->generateOtpCode($secret),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testMeFeaturesRequiresAuthentication(): void
    {
        $client = $this->createIsolatedClient('10.0.0.54');

        $client->request('GET', '/api/v1/auth/me/features');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeFeaturesReturnsAndUpdatesFeaturePreferences(): void
    {
        $client = $this->createIsolatedClient('10.0.0.55');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/auth/me/features');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['feature_governance'] ?? null);
        self::assertIsArray($payload['core_v1_global_features'] ?? null);
        self::assertIsArray($payload['effective_feature_enabled'] ?? null);

        $client->jsonRequest('PATCH', '/api/v1/auth/me/features', [
            'user_feature_enabled' => [
                'features.ai.suggest_tags' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $patched = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(false, $patched['user_feature_enabled']['features.ai.suggest_tags'] ?? null);
        self::assertSame(false, $patched['effective_feature_enabled']['features.ai.suggest_tags'] ?? null);
        self::assertSame(false, $patched['effective_feature_enabled']['features.ai.suggested_tags_filters'] ?? null);
    }

    public function testMeFeaturesRejectsDisablingCoreV1GlobalFeature(): void
    {
        $client = $this->createIsolatedClient('10.0.0.56');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PATCH', '/api/v1/auth/me/features', [
            'user_feature_enabled' => [
                'features.core.auth' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    public function testMeFeaturesRejectsUnknownFeatureKey(): void
    {
        $client = $this->createIsolatedClient('10.0.1.56');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PATCH', '/api/v1/auth/me/features', [
            'user_feature_enabled' => [
                'features.unknown.flag' => true,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame(['features.unknown.flag'], $payload['details']['unknown_keys'] ?? null);
    }

    public function testMeFeaturesRejectsNonBooleanFeatureValue(): void
    {
        $client = $this->createIsolatedClient('10.0.2.56');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PATCH', '/api/v1/auth/me/features', [
            'user_feature_enabled' => [
                'features.ai.suggest_tags' => 'off',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame(['features.ai.suggest_tags'], $payload['details']['non_boolean_keys'] ?? null);
    }

    public function testAppFeaturesAreAdminOnly(): void
    {
        $client = $this->createIsolatedClient('10.0.0.57');
        $email = sprintf('operator-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], true);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/app/features');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testAdminCanReadAndPatchAppFeatures(): void
    {
        $client = $this->createIsolatedClient('10.0.0.58');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/app/features');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['app_feature_enabled'] ?? null);
        self::assertIsArray($payload['feature_governance'] ?? null);
        self::assertIsArray($payload['core_v1_global_features'] ?? null);

        $client->jsonRequest('PATCH', '/api/v1/app/features', [
            'app_feature_enabled' => [
                'features.ai' => false,
                'features.ai.suggest_tags' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $patched = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(false, $patched['app_feature_enabled']['features.ai'] ?? null);
        self::assertSame(false, $patched['app_feature_enabled']['features.ai.suggest_tags'] ?? null);
    }

    public function testAdminPatchAppFeaturesRejectsUnknownFeatureKey(): void
    {
        $client = $this->createIsolatedClient('10.0.1.58');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PATCH', '/api/v1/app/features', [
            'app_feature_enabled' => [
                'features.unknown.flag' => true,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame(['features.unknown.flag'], $payload['details']['unknown_keys'] ?? null);
    }

    public function testAdminPatchAppFeaturesRejectsNonBooleanFeatureValue(): void
    {
        $client = $this->createIsolatedClient('10.0.2.58');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('PATCH', '/api/v1/app/features', [
            'app_feature_enabled' => [
                'features.ai' => 'disabled',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
        self::assertSame(['features.ai'], $payload['details']['non_boolean_keys'] ?? null);
    }

    public function testClientTokenMintReturnsTokenForAgentClient(): void
    {
        $client = $this->createIsolatedClient('10.0.0.59');

        $client->jsonRequest('POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'agent-secret',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertStringStartsWith('ct_', (string) ($payload['access_token'] ?? ''));
        self::assertSame('Bearer', $payload['token_type'] ?? null);
    }

    public function testClientTokenMintRejectsUiRustClientKind(): void
    {
        $client = $this->createIsolatedClient('10.0.0.60');

        $client->jsonRequest('POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'UI_RUST',
            'secret_key' => 'agent-secret',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testClientTokenMintRejectsInvalidCredentials(): void
    {
        $client = $this->createIsolatedClient('10.0.0.61');

        $client->jsonRequest('POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => 'wrong-secret',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testClientAdminEndpointsAreAdminOnly(): void
    {
        $client = $this->createIsolatedClient('10.0.0.62');
        $email = sprintf('operator-%s@retaia.local', bin2hex(random_bytes(4)));
        $this->insertUser($email, 'change-me', ['ROLE_USER'], true);

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/clients/agent-default/revoke-token');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testAdminCanRevokeAndRotateClientCredentials(): void
    {
        $client = $this->createIsolatedClient('10.0.0.63');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/clients/agent-default/revoke-token');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $revoked = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(true, $revoked['revoked'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/clients/agent-default/rotate-secret');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $rotated = json_decode($client->getResponse()->getContent(), true);
        $newSecret = (string) ($rotated['secret_key'] ?? '');
        self::assertNotSame('', $newSecret);

        $client->jsonRequest('POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'AGENT',
            'secret_key' => $newSecret,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testDeviceFlowStartPollAndCancel(): void
    {
        $client = $this->createIsolatedClient('10.0.0.64');

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $startPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($startPayload);
        $deviceCode = (string) ($startPayload['device_code'] ?? '');
        self::assertNotSame('', $deviceCode);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $pollPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('PENDING', $pollPayload['status'] ?? null);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/cancel', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $cancelPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(true, $cancelPayload['canceled'] ?? null);
    }

    public function testDeviceFlowPollRejectsInvalidCode(): void
    {
        $client = $this->createIsolatedClient('10.0.0.65');

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('INVALID_DEVICE_CODE', $payload['code'] ?? null);
    }

    public function testDeviceFlowPollReturnsDeniedAfterCancel(): void
    {
        $client = $this->createIsolatedClient('10.0.0.67');

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $startPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($startPayload);
        $deviceCode = (string) ($startPayload['device_code'] ?? '');
        self::assertNotSame('', $deviceCode);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/cancel', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $pollPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($pollPayload);
        self::assertSame('DENIED', $pollPayload['status'] ?? null);
    }

    public function testDeviceFlowPollReturnsExpiredStatusWhenFlowExpired(): void
    {
        $client = $this->createIsolatedClient('10.0.0.68');

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $startPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($startPayload);
        $deviceCode = (string) ($startPayload['device_code'] ?? '');
        self::assertNotSame('', $deviceCode);

        $this->forceDeviceFlowExpiration($deviceCode);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $pollPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($pollPayload);
        self::assertSame('EXPIRED', $pollPayload['status'] ?? null);
    }

    public function testDeviceFlowPollReturnsSlowDownWhenPolledTooFast(): void
    {
        $client = $this->createIsolatedClient('10.0.0.66');

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $startPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($startPayload);
        $deviceCode = (string) ($startPayload['device_code'] ?? '');
        self::assertNotSame('', $deviceCode);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('SLOW_DOWN', $payload['code'] ?? null);
        self::assertGreaterThanOrEqual(1, (int) ($payload['retry_in_seconds'] ?? 0));
    }

    public function testUnsupportedLocaleFallsBackToEnglishAuthenticationRequiredMessage(): void
    {
        $client = $this->createIsolatedClient('10.0.0.35', 'de');

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
        self::assertSame('Authentication required', $payload['message'] ?? null);
    }

    public function testAgentRegisterRequiresAgentScope(): void
    {
        $client = $this->createIsolatedClient('10.0.0.32');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'capabilities' => ['extract_facts'],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    public function testAgentRegisterReturnsServerPolicy(): void
    {
        $client = $this->createIsolatedClient('10.0.0.33');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'capabilities' => ['extract_facts', 'generate_proxy'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['agent_id'] ?? null);
        self::assertSame(5, $payload['server_policy']['min_poll_interval_seconds'] ?? null);
        self::assertSame(false, $payload['server_policy']['features']['ai']['suggest_tags'] ?? null);
        self::assertSame('1.0.0', $payload['server_policy']['feature_flags_contract_version'] ?? null);
        self::assertSame('1.0.0', $payload['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('STRICT', $payload['server_policy']['feature_flags_compatibility_mode'] ?? null);
        self::assertSame(false, $payload['server_policy']['feature_flags']['features.ai.suggest_tags'] ?? null);
    }

    public function testAgentRegisterSupportsCompatContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.44');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'client_feature_flags_contract_version' => '0.9.0',
            'capabilities' => ['extract_facts', 'generate_proxy'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('0.9.0', $payload['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('COMPAT', $payload['server_policy']['feature_flags_compatibility_mode'] ?? null);
    }

    public function testAgentRegisterRejectsUnsupportedContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.45');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/agents/register', [
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'client_feature_flags_contract_version' => '2.0.0',
            'capabilities' => ['extract_facts', 'generate_proxy'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UPGRADE_REQUIRED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION', $payload['code'] ?? null);
    }

    public function testAppPolicyRequiresAuthentication(): void
    {
        $client = $this->createIsolatedClient('10.0.0.40');

        $client->request('GET', '/api/v1/app/policy');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testAppPolicyReturnsServerPolicy(): void
    {
        $client = $this->createIsolatedClient('10.0.0.41');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/app/policy');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('1.0.0', $payload['server_policy']['feature_flags_contract_version'] ?? null);
        self::assertSame('1.0.0', $payload['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('STRICT', $payload['server_policy']['feature_flags_compatibility_mode'] ?? null);
        self::assertSame(false, $payload['server_policy']['feature_flags']['features.ai.suggest_tags'] ?? null);
        self::assertSame(false, $payload['server_policy']['feature_flags']['features.decisions.bulk'] ?? null);
    }

    public function testAppPolicyCanServeCompatContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.42');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/app/policy?client_feature_flags_contract_version=0.9.0');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('0.9.0', $payload['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('COMPAT', $payload['server_policy']['feature_flags_compatibility_mode'] ?? null);
    }

    public function testAppPolicyRejectsUnsupportedContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.43');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->request('GET', '/api/v1/app/policy?client_feature_flags_contract_version=2.0.0');
        self::assertResponseStatusCodeSame(Response::HTTP_UPGRADE_REQUIRED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION', $payload['code'] ?? null);
    }

    private function createIsolatedClient(string $ipAddress, ?string $acceptLanguage = null): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $server = ['REMOTE_ADDR' => $ipAddress];
        if (is_string($acceptLanguage) && $acceptLanguage !== '') {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }

        $client = static::createClient([], $server);
        $client->disableReboot();

        return $client;
    }

    /**
     * @param array<int, string> $roles
     */
    private function insertUser(string $email, string $plainPassword, array $roles, bool $emailVerified): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->insert('app_user', [
            'id' => bin2hex(random_bytes(16)),
            'email' => $email,
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'email_verified' => $emailVerified ? 1 : 0,
        ]);
    }

    private function forceDeviceFlowExpiration(string $deviceCode): void
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $item = $cache->getItem('auth_device_flows');
        $flows = $item->get();
        if (!is_array($flows) || !isset($flows[$deviceCode]) || !is_array($flows[$deviceCode])) {
            self::fail('Device flow not found in cache for expiration fixture.');
        }

        $flow = $flows[$deviceCode];
        $flow['expires_at'] = time() - 1;
        $flows[$deviceCode] = $flow;
        $item->set($flows);
        $cache->save($item);
    }

    private function generateOtpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    private function generateOtpCodeAt(string $secret, int $timestamp): string
    {
        return TOTP::createFromSecret($secret)->at($timestamp);
    }
}
