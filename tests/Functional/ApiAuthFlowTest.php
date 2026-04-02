<?php

namespace App\Tests\Functional;

use App\Tests\Functional\Support\AuthDeviceFlowCacheTrait;
use App\Tests\Support\AgentSigningTestHelper;
use App\Tests\Support\FixtureUsers;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use OTPHP\TOTP;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiAuthFlowTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use AuthDeviceFlowCacheTrait;
    use FunctionalSchemaTrait;

    public function testRefreshRotatesInteractiveTokens(): void
    {
        $client = $this->createIsolatedClient('10.0.0.121');

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
            'client_id' => 'interactive-refresh',
            'client_kind' => 'UI_WEB',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $loginPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($loginPayload);
        $firstAccessToken = $loginPayload['access_token'] ?? null;
        $refreshToken = $loginPayload['refresh_token'] ?? null;
        self::assertIsString($firstAccessToken);
        self::assertIsString($refreshToken);

        $client->jsonRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
            'client_id' => 'interactive-refresh',
            'client_kind' => 'UI_WEB',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $refreshPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($refreshPayload);
        self::assertIsString($refreshPayload['access_token'] ?? null);
        self::assertIsString($refreshPayload['refresh_token'] ?? null);
        self::assertNotSame($firstAccessToken, $refreshPayload['access_token']);
        self::assertNotSame($refreshToken, $refreshPayload['refresh_token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$firstAccessToken);
        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$refreshPayload['access_token']);
        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        $client->jsonRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $refreshToken,
            'client_id' => 'interactive-refresh',
            'client_kind' => 'UI_WEB',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $staleRefreshPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('UNAUTHORIZED', $staleRefreshPayload['code'] ?? null);
    }

    public function testMySessionsListCurrentAndOtherSessions(): void
    {
        $email = sprintf('sessions-%s@retaia.local', bin2hex(random_bytes(4)));
        $currentClient = $this->createIsolatedClient('10.0.0.18');
        $this->insertUser($email, 'Change-me1!', ['ROLE_USER'], true);
        $this->loginAndAttachBearer($currentClient, [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-current',
        ]);
        $currentPayload = json_decode((string) $currentClient->getResponse()->getContent(), true);
        self::assertIsArray($currentPayload);
        $currentToken = $currentPayload['access_token'] ?? null;
        self::assertIsString($currentToken);

        $currentClient->setServerParameter('HTTP_AUTHORIZATION', '');
        $currentClient->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-other',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $currentClient->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$currentToken);
        $currentClient->request('GET', '/api/v1/auth/me/sessions');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $currentClient->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(2, $payload['items'] ?? []);
        self::assertTrue((bool) ($payload['items'][0]['is_current'] ?? false));
        self::assertSame('interactive-current', $payload['items'][0]['client_id'] ?? null);
    }

    public function testCurrentSessionCannotBeSelfRevoked(): void
    {
        $client = $this->createIsolatedClient('10.0.0.20');
        $this->loginAndAttachBearer($client, [
            'email' => FixtureUsers::ADMIN_EMAIL,
            'password' => FixtureUsers::DEFAULT_PASSWORD,
            'client_id' => 'interactive-self-revoke',
        ]);

        $client->request('GET', '/api/v1/auth/me/sessions');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $sessionsPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($sessionsPayload);
        $sessionId = $sessionsPayload['items'][0]['session_id'] ?? null;
        self::assertIsString($sessionId);

        $client->jsonRequest('POST', '/api/v1/auth/me/sessions/'.$sessionId.'/revoke');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testUnknownForeignSessionRevokeReturns404(): void
    {
        $email = sprintf('sessions-404-%s@retaia.local', bin2hex(random_bytes(4)));
        $client = $this->createIsolatedClient('10.0.0.201');
        $this->insertUser($email, 'Change-me1!', ['ROLE_USER'], true);

        $this->loginAndAttachBearer($client, [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-404',
        ]);

        $client->jsonRequest('POST', '/api/v1/auth/me/sessions/unknown-session-id/revoke');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
    }

    public function testRevokeOtherSessionsRevokesOnlyOtherSessions(): void
    {
        $email = sprintf('sessions-revoke-%s@retaia.local', bin2hex(random_bytes(4)));
        $currentClient = $this->createIsolatedClient('10.0.0.21');
        $this->insertUser($email, 'Change-me1!', ['ROLE_USER'], true);
        $this->loginAndAttachBearer($currentClient, [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-primary',
        ]);
        $primaryPayload = json_decode((string) $currentClient->getResponse()->getContent(), true);
        self::assertIsArray($primaryPayload);
        $primaryToken = $primaryPayload['access_token'] ?? null;
        self::assertIsString($primaryToken);

        $currentClient->setServerParameter('HTTP_AUTHORIZATION', '');
        $currentClient->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-secondary',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondaryPayload = json_decode((string) $currentClient->getResponse()->getContent(), true);
        self::assertIsArray($secondaryPayload);
        $secondaryToken = $secondaryPayload['access_token'] ?? null;
        self::assertIsString($secondaryToken);

        $currentClient->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$primaryToken);
        $currentClient->jsonRequest('POST', '/api/v1/auth/me/sessions/revoke-others');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $currentClient->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, (int) ($payload['revoked'] ?? 0));

        $currentClient->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$secondaryToken);
        $currentClient->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRevokeOtherSessionsReturnsZeroWhenNoPeerSessionExists(): void
    {
        $email = sprintf('sessions-solo-%s@retaia.local', bin2hex(random_bytes(4)));
        $client = $this->createIsolatedClient('10.0.0.211');
        $this->insertUser($email, 'Change-me1!', ['ROLE_USER'], true);

        $this->loginAndAttachBearer($client, [
            'email' => $email,
            'password' => 'Change-me1!',
            'client_id' => 'interactive-solo',
        ]);

        $client->jsonRequest('POST', '/api/v1/auth/me/sessions/revoke-others');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(0, (int) ($payload['revoked'] ?? -1));

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
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

    public function testTwoFactorEnableAndDisableFlow(): void
    {
        $client = $this->createIsolatedClient('10.0.0.52');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

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

        $otpCode = $this->generateOtpCode($secret);
        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
            'otp_code' => $otpCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $otpCode = $this->generateOtpCode($secret);
        $client->jsonRequest('POST', '/api/v1/auth/2fa/disable', ['otp_code' => $otpCode]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testMeFeaturesReturnsAndUpdatesFeaturePreferences(): void
    {
        $client = $this->createIsolatedClient('10.0.0.55');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

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

    public function testAdminCanReadAndPatchAppFeatures(): void
    {
        $client = $this->createIsolatedClient('10.0.0.58');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

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

    public function testAdminCanUpdateAppPolicyThroughOpenApiRoute(): void
    {
        $client = $this->createIsolatedClient('10.0.0.581');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        $client->jsonRequest('POST', '/api/v1/app/policy', [
            'feature_flags' => [
                'features.ai' => false,
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(false, $payload['server_policy']['feature_flags']['features.ai.suggest_tags'] ?? null);
    }

    public function testAdminCanRevokeAndRotateClientCredentials(): void
    {
        $client = $this->createIsolatedClient('10.0.0.63');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

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

    public function testDeviceFlowPollRecordsStatusMetrics(): void
    {
        $client = $this->createIsolatedClient('10.0.0.69');
        $this->ensureMetricTable();

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

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/cancel', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->forceDeviceFlowLastPolledAt($deviceCode, 0);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $deviceCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/start', [
            'client_kind' => 'AGENT',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondStartPayload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($secondStartPayload);
        $expiringCode = (string) ($secondStartPayload['device_code'] ?? '');
        self::assertNotSame('', $expiringCode);
        $this->forceDeviceFlowExpiration($expiringCode);

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => $expiringCode,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSame(1, $this->countMetricEvents('auth.device.poll.status.PENDING'));
        self::assertSame(1, $this->countMetricEvents('auth.device.poll.status.DENIED'));
        self::assertSame(1, $this->countMetricEvents('auth.device.poll.status.EXPIRED'));
    }

    public function testDeviceFlowPollErrorMetricsAreRecorded(): void
    {
        $client = $this->createIsolatedClient('10.0.0.70');
        $this->ensureMetricTable();

        $client->jsonRequest('POST', '/api/v1/auth/clients/device/poll', [
            'device_code' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

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

        self::assertSame(1, $this->countMetricEvents('auth.device.poll.invalid_device_code'));
        self::assertSame(1, $this->countMetricEvents('auth.device.poll.throttled'));
    }

    public function testClientTokenUiWebForbiddenMetricIsRecorded(): void
    {
        $client = $this->createIsolatedClient('10.0.0.71');
        $this->ensureMetricTable();

        $client->jsonRequest('POST', '/api/v1/auth/clients/token', [
            'client_id' => 'agent-default',
            'client_kind' => 'UI_WEB',
            'secret_key' => 'agent-secret',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
        self::assertSame(1, $this->countMetricEvents('auth.client.token.forbidden_actor.ui_web'));
    }

    public function testAgentRegisterRequiresAgentScope(): void
    {
        $client = $this->createIsolatedClient('10.0.0.32');

        $this->loginAndAttachBearer($client, [
            'email' => 'admin@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'capabilities' => ['extract_facts'],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_SCOPE', $payload['code'] ?? null);
    }

    public function testAgentRegisterRejectsInvalidPayload(): void
    {
        $client = $this->createIsolatedClient('10.0.0.46');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'os_name' => 'invalid-os',
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testAgentRegisterRejectsNonStringCapabilities(): void
    {
        $client = $this->createIsolatedClient('10.0.0.461');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'capabilities' => ['extract_facts', 12],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testAgentRegisterRejectsMalformedClientContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.462');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'client_feature_flags_contract_version' => '1.0',
            'capabilities' => ['extract_facts'],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testAgentRegisterReturnsServerPolicy(): void
    {
        $client = $this->createIsolatedClient('10.0.0.33');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'capabilities' => ['extract_facts', 'generate_preview'],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(AgentSigningTestHelper::publicMaterial()['agent_id'], $payload['agent_id'] ?? null);
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

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'client_feature_flags_contract_version' => '0.9.0',
            'capabilities' => ['extract_facts', 'generate_preview'],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('0.9.0', $payload['server_policy']['effective_feature_flags_contract_version'] ?? null);
        self::assertSame('COMPAT', $payload['server_policy']['feature_flags_compatibility_mode'] ?? null);
    }

    public function testAgentRegisterRejectsUnsupportedContractVersion(): void
    {
        $client = $this->createIsolatedClient('10.0.0.45');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload([
            'client_feature_flags_contract_version' => '2.0.0',
            'capabilities' => ['extract_facts', 'generate_preview'],
        ]);
        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $this->agentSignatureHeaders($payload));

        self::assertResponseStatusCodeSame(Response::HTTP_UPGRADE_REQUIRED);
        $payload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('UNSUPPORTED_FEATURE_FLAGS_CONTRACT_VERSION', $payload['code'] ?? null);
    }

    public function testAgentRegisterRejectsForgedSignature(): void
    {
        $client = $this->createIsolatedClient('10.0.0.47');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload();
        $headers = $this->agentSignatureHeaders($payload);
        $headers['HTTP_X_RETAIA_SIGNATURE'] = base64_encode('forged-signature');

        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $responsePayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['X-Retaia-Signature'], $responsePayload['details']['invalid_headers'] ?? null);
    }

    public function testAgentRegisterRejectsReplayNonce(): void
    {
        $client = $this->createIsolatedClient('10.0.0.48');

        $this->loginAndAttachBearer($client, [
            'email' => 'agent@retaia.local',
            'password' => 'change-me',
        ]);

        $payload = $this->agentRegisterPayload();
        $headers = AgentSigningTestHelper::signedHeaders('POST', '/api/v1/agents/register', $payload, 'replay-nonce');

        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/agents/register', $payload, $headers);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $responsePayload = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['X-Retaia-Signature-Nonce'], $responsePayload['details']['invalid_headers'] ?? null);
    }

    private function createIsolatedClient(string $ipAddress, ?string $acceptLanguage = null): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $server = ['REMOTE_ADDR' => $ipAddress];
        if (is_string($acceptLanguage) && $acceptLanguage !== '') {
            $server['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        }

        self::ensureKernelShutdown();
        $client = static::createClient([], $server);
        $client->disableReboot();
        $connection = static::getContainer()->get(Connection::class);
        $this->ensureAuthClientTables($connection);
        $this->ensureUserAuthSessionTable($connection);
        $this->ensureUserTwoFactorStateTable($connection);
        $connection->executeStatement('DELETE FROM auth_client_access_token');
        $connection->executeStatement('DELETE FROM auth_device_flow');
        $connection->executeStatement('DELETE FROM auth_mcp_challenge');
        $connection->executeStatement('DELETE FROM user_auth_session');
        $connection->executeStatement('DELETE FROM user_two_factor_state');
        $this->ensureAgentRuntimeTable($connection);
        $this->ensureAgentSignatureTables($connection);
        $connection->executeStatement('DELETE FROM agent_runtime');
        $connection->executeStatement('DELETE FROM agent_public_key');
        $connection->executeStatement('DELETE FROM agent_signature_nonce');
        /** @var CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.app');
        $cache->clear();

        return $client;
    }

    /**
     * @return array<string, string>
     */
    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function agentSignatureHeaders(array $payload): array
    {
        return AgentSigningTestHelper::signedHeaders('POST', '/api/v1/agents/register', $payload);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function agentRegisterPayload(array $overrides = []): array
    {
        $material = AgentSigningTestHelper::publicMaterial();

        return array_replace([
            'agent_id' => $material['agent_id'],
            'agent_name' => 'ffmpeg-worker',
            'agent_version' => '1.0.0',
            'openpgp_public_key' => $material['public_key'],
            'openpgp_fingerprint' => $material['fingerprint'],
            'os_name' => 'linux',
            'os_version' => '6.8',
            'arch' => 'x86_64',
            'capabilities' => ['extract_facts'],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loginAndAttachBearer(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $payload): void
    {
        if (!array_key_exists('client_id', $payload)) {
            $payload['client_id'] = 'interactive-'.bin2hex(random_bytes(6));
        }
        if (!array_key_exists('client_kind', $payload)) {
            $payload['client_kind'] = 'UI_WEB';
        }

        $client->jsonRequest('POST', '/api/v1/auth/login', $payload);

        $responsePayload = json_decode((string) $client->getResponse()->getContent(), true);
        $accessToken = is_array($responsePayload) ? ($responsePayload['access_token'] ?? null) : null;
        if (is_string($accessToken) && $accessToken !== '') {
            $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$accessToken);

            return;
        }

        $client->setServerParameter('HTTP_AUTHORIZATION', '');
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
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function generateOtpCode(string $secret): string
    {
        return TOTP::createFromSecret($secret)->now();
    }

    private function countMetricEvents(string $metricKey): int
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ops_metric_event WHERE metric_key = :metricKey',
            ['metricKey' => $metricKey]
        );
    }

    private function ensureMetricTable(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ops_metric_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric_key VARCHAR(128) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_ops_metric_event_key ON ops_metric_event (metric_key)');
        $connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_ops_metric_event_created_at ON ops_metric_event (created_at)');
    }
}
