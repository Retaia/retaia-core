<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\ApiAuthClientTrait;
use App\Tests\Support\FixtureUsers;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

final class OpenApiContractTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use ApiAuthClientTrait;
    use FunctionalSchemaTrait;

    public function testCriticalEndpointsDeclareIdempotencyHeaderInOpenApi(): void
    {
        $openApi = $this->openApi();

        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/reprocess', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/jobs/{job_id}/submit', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/jobs/{job_id}/fail', 'post');
        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/purge', 'post');
    }

    public function testErrorResponseRuntimeMatchesOpenApiErrorModel(): void
    {
        $openApi = $this->openApi();
        $errorSchema = $this->errorSchema($openApi);
        $errorCodes = $this->errorCodes($errorSchema);

        $client = $this->createAuthenticatedClient();
        $this->ensureAuxiliaryTables();
        $this->seedAsset('11111111-1111-1111-1111-111111111111', AssetState::PROCESSED);

        $client->jsonRequest('POST', '/api/v1/assets/11111111-1111-1111-1111-111111111111/purge', [], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
            'HTTP_IDEMPOTENCY_KEY' => 'contract-purge-conflict-1',
            'HTTP_X_CORRELATION_ID' => 'contract-correlation-id',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertIsString($payload['code'] ?? null);
        self::assertContains((string) $payload['code'], $errorCodes);
        self::assertIsString($payload['message'] ?? null);
        self::assertIsBool($payload['retryable'] ?? null);
        self::assertSame('contract-correlation-id', $payload['correlation_id'] ?? null);
    }

    public function testAuthEndpointsDeclareErrorModelInOpenApi(): void
    {
        $openApi = $this->openApi();

        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '401');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '403');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/login', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/me', 'get', '401');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/request', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/request', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/reset', 'post', '400');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/lost-password/reset', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/request', 'post', '422');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/request', 'post', '429');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/confirm', 'post', '400');
        $this->assertPathStatusUsesErrorResponse($openApi, '/auth/verify-email/confirm', 'post', '422');
    }

    public function testJobEndpointsDeclareLockErrorModelInOpenApi(): void
    {
        $openApi = $this->openApi();

        $this->assertPathStatusUsesErrorResponse($openApi, '/jobs/{job_id}/submit', 'post', '423');
    }

    public function testJobLeaseSchemasDeclareCurrentFencingAndTypeContracts(): void
    {
        $openApi = $this->openApi();

        $jobSchema = $openApi['components']['schemas']['Job'] ?? null;
        self::assertIsArray($jobSchema);
        self::assertContains('generate_preview', $jobSchema['properties']['job_type']['enum'] ?? []);
        self::assertContains('transcribe_audio', $jobSchema['properties']['job_type']['enum'] ?? []);
        self::assertSame('integer', $jobSchema['properties']['fencing_token']['type'] ?? null);

        $heartbeatRequest = $openApi['paths']['/jobs/{job_id}/heartbeat']['post']['requestBody']['content']['application/json']['schema'] ?? null;
        self::assertIsArray($heartbeatRequest);
        self::assertContains('fencing_token', $heartbeatRequest['required'] ?? []);

        $submitDerived = $openApi['components']['schemas']['SubmitDerived'] ?? null;
        self::assertIsArray($submitDerived);
        self::assertContains('generate_preview', $submitDerived['properties']['job_type']['enum'] ?? []);
    }

    public function testErrorCodeEnumIncludesRuntimeCodes(): void
    {
        $openApi = $this->openApi();
        $errorSchema = $this->errorSchema($openApi);
        $errorCodes = $this->errorCodes($errorSchema);

        $runtimeCodes = [
            'UNAUTHORIZED',
            'FORBIDDEN_SCOPE',
            'FORBIDDEN_ACTOR',
            'EMAIL_NOT_VERIFIED',
            'STATE_CONFLICT',
            'IDEMPOTENCY_CONFLICT',
            'STALE_LOCK_TOKEN',
            'NAME_COLLISION_EXHAUSTED',
            'PURGED',
            'INVALID_TOKEN',
            'USER_NOT_FOUND',
            'VALIDATION_FAILED',
            'LOCK_REQUIRED',
            'LOCK_INVALID',
            'TOO_MANY_ATTEMPTS',
            'TEMPORARY_UNAVAILABLE',
        ];

        foreach ($runtimeCodes as $code) {
            self::assertContains($code, $errorCodes, sprintf('OpenAPI ErrorResponse enum must include %s.', $code));
        }
    }

    public function testAssetDerivedWaveformUrlIsOptionalAndNullableInOpenApi(): void
    {
        $openApi = $this->openApi();

        $assetDerived = $openApi['components']['schemas']['AssetDerived'] ?? null;
        self::assertIsArray($assetDerived);
        self::assertSame('object', $assetDerived['type'] ?? null);

        $waveform = $assetDerived['properties']['waveform_url'] ?? null;
        self::assertIsArray($waveform);
        self::assertSame('string', $waveform['type'] ?? null);
        self::assertTrue((bool) ($waveform['nullable'] ?? false));

        $required = $assetDerived['required'] ?? [];
        self::assertIsArray($required);
        self::assertNotContains('waveform_url', $required);
    }

    public function testAssetDetailProjectsAreDeclaredInOpenApi(): void
    {
        $openApi = $this->openApi();

        $assetDetail = $openApi['components']['schemas']['AssetDetail'] ?? null;
        self::assertIsArray($assetDetail);
        $projects = $assetDetail['properties']['projects'] ?? null;
        self::assertIsArray($projects);
        self::assertSame('array', $projects['type'] ?? null);
        self::assertSame('#/components/schemas/AssetProjectRef', $projects['items']['$ref'] ?? null);

        $projectRef = $openApi['components']['schemas']['AssetProjectRef'] ?? null;
        self::assertIsArray($projectRef);
        self::assertSame(['project_id', 'project_name', 'created_at'], $projectRef['required'] ?? null);
    }

    public function testAssetReadResponsesMatchCurrentContractHeadersAndShape(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedDetailedAsset('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $this->seedDetailedAsset('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'AUDIO', 'contract-audio.wav', AssetState::PROCESSED, [
            'captured_at' => '2026-01-11T12:00:00Z',
            'gps_latitude' => 48.8566,
            'gps_longitude' => 2.3522,
            'location_country' => 'FR',
            'location_city' => 'Paris',
        ]);

        $client->request('GET', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertCacheControlIsPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
        $detailPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($detailPayload);
        self::assertSame($detailPayload['summary']['revision_etag'] ?? null, $client->getResponse()->headers->get('ETag'));
        self::assertArrayHasKey('has_preview', $detailPayload['summary'] ?? []);
        self::assertArrayNotHasKey('has_proxy', $detailPayload['summary'] ?? []);
        self::assertSame(50.8503, $detailPayload['gps_latitude'] ?? null);
        self::assertSame('BE', $detailPayload['location_country'] ?? null);
        self::assertSame('Brussels', $detailPayload['location_city'] ?? null);

        $client->request('GET', '/api/v1/assets?has_preview=true&location_country=BE&location_city=Brussels&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertCacheControlIsPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
        $listPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($listPayload);
        self::assertCount(1, $listPayload['items'] ?? []);
        self::assertSame('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $listPayload['items'][0]['uuid'] ?? null);
        self::assertSame(true, $listPayload['items'][0]['has_preview'] ?? null);
        self::assertArrayNotHasKey('has_proxy', $listPayload['items'][0] ?? []);
    }

    public function testAssetListCursorAndStatePatchRuntimeBehavior(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedDetailedAsset('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        $this->seedDetailedAsset('cccccccc-cccc-cccc-cccc-cccccccccccc', 'PHOTO', 'archive-photo.jpg', AssetState::ARCHIVED, [
            'captured_at' => '2026-01-12T12:00:00Z',
            'gps_latitude' => 50.8510,
            'gps_longitude' => 4.3520,
            'location_country' => 'BE',
            'location_city' => 'Brussels',
        ]);

        $client->request('GET', '/api/v1/assets?state=ARCHIVED,PROCESSED&geo_bbox=4.30,50.80,4.45,50.92&sort=name&limit=1');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $firstPage = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($firstPage);
        self::assertCount(1, $firstPage['items'] ?? []);
        self::assertSame('cccccccc-cccc-cccc-cccc-cccccccccccc', $firstPage['items'][0]['uuid'] ?? null);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        $client->request('GET', '/api/v1/assets?state=ARCHIVED,PROCESSED&geo_bbox=4.30,50.80,4.45,50.92&sort=name&limit=1&cursor='.rawurlencode((string) $firstPage['next_cursor']));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondPage = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($secondPage);
        self::assertCount(1, $secondPage['items'] ?? []);
        self::assertSame('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $secondPage['items'][0]['uuid'] ?? null);

        $client->jsonRequest('PATCH', '/api/v1/assets/cccccccc-cccc-cccc-cccc-cccccccccccc', [
            'state' => 'DECISION_PENDING',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, 'cccccccc-cccc-cccc-cccc-cccccccccccc'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $patched = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($patched);
        self::assertSame('DECISION_PENDING', $patched['state'] ?? null);
    }

    public function testRuntimeContractKeepsPollingAsSourceOfTruth(): void
    {
        $openApi = $this->openApi();
        $paths = $openApi['paths'] ?? [];
        self::assertIsArray($paths);
        self::assertArrayHasKey('/app/policy', $paths);
        self::assertArrayHasKey('/auth/clients/device/poll', $paths);
        $methods = [];
        foreach ($paths as $operations) {
            if (!is_array($operations)) {
                continue;
            }

            foreach (array_keys($operations) as $method) {
                if (!is_string($method)) {
                    continue;
                }

                $normalized = strtolower($method);
                if (in_array($normalized, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $methods[$normalized] = true;
                }
            }
        }

        self::assertArrayHasKey('post', $methods, 'Mutating REST operations must remain available by contract.');
        self::assertArrayHasKey('patch', $methods, 'Mutating REST operations must remain available by contract.');
    }

    public function testReprocessRequestWithoutIdempotencyKeyIsRejected(): void
    {
        $openApi = $this->openApi();
        $this->assertPathHasIdempotencyHeader($openApi, '/assets/{uuid}/reprocess', 'post');

        $client = $this->createAuthenticatedClient();
        $this->ensureAuxiliaryTables();
        $this->seedAsset('22222222-2222-2222-2222-222222222222', AssetState::DECISION_PENDING);

        $client->jsonRequest('POST', '/api/v1/assets/22222222-2222-2222-2222-222222222222/reprocess', [], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '22222222-2222-2222-2222-222222222222'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('MISSING_IDEMPOTENCY_KEY', $payload['code'] ?? null);
        self::assertArrayHasKey('retryable', $payload);
        self::assertArrayHasKey('correlation_id', $payload);
    }

    public function testAuthUnauthorizedErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('UNAUTHORIZED', $payload['code'] ?? null);
    }

    public function testAuthValidationErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', []);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testAuthRateLimitErrorMatchesOpenApiModel(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $status = Response::HTTP_ACCEPTED;
        for ($i = 0; $i < 6; ++$i) {
            $client->jsonRequest('POST', '/api/v1/auth/lost-password/request', [
                'email' => 'admin@retaia.local',
            ]);
            $status = $client->getResponse()->getStatusCode();
            if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
                break;
            }
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        self::assertSame('TOO_MANY_ATTEMPTS', $payload['code'] ?? null);
    }

    public function testAuthSelfServiceRuntimeShapesMatchCurrentOpenApiSchemas(): void
    {
        $openApi = $this->openApi();

        $authCurrentUser = $openApi['components']['schemas']['AuthCurrentUser'] ?? null;
        self::assertIsArray($authCurrentUser);
        self::assertSame(['email'], $authCurrentUser['required'] ?? null);
        self::assertTrue((bool) ($authCurrentUser['additionalProperties'] ?? false));
        self::assertSame('boolean', $authCurrentUser['properties']['email_verified']['type'] ?? null);
        self::assertSame('boolean', $authCurrentUser['properties']['mfa_enabled']['type'] ?? null);
        self::assertSame('array', $authCurrentUser['properties']['roles']['type'] ?? null);

        $authSession = $openApi['components']['schemas']['AuthSession'] ?? null;
        self::assertIsArray($authSession);
        self::assertSame(['session_id', 'client_id', 'created_at', 'last_used_at', 'is_current'], $authSession['required'] ?? null);
        self::assertSame('string', $authSession['properties']['expires_at']['type'] ?? null);
        self::assertTrue((bool) ($authSession['properties']['expires_at']['nullable'] ?? false));
        self::assertTrue((bool) ($authSession['properties']['device_label']['nullable'] ?? false));
        self::assertTrue((bool) ($authSession['properties']['browser']['nullable'] ?? false));
        self::assertTrue((bool) ($authSession['properties']['os']['nullable'] ?? false));
        self::assertTrue((bool) ($authSession['properties']['ip_address_last_seen']['nullable'] ?? false));

        $setupSchema = $openApi['components']['schemas']['Auth2faSetupResponse'] ?? null;
        self::assertIsArray($setupSchema);
        self::assertSame(['method', 'issuer', 'account_name', 'secret', 'otpauth_uri'], $setupSchema['required'] ?? null);
        self::assertArrayNotHasKey('qr_svg', array_flip($setupSchema['required'] ?? []));
        self::assertSame('string', $setupSchema['properties']['qr_svg']['type'] ?? null);

        $enableSchema = $openApi['components']['schemas']['Auth2faEnableResponse'] ?? null;
        self::assertIsArray($enableSchema);
        self::assertSame(['mfa_enabled', 'recovery_codes'], $enableSchema['required'] ?? null);

        $recoverySchema = $openApi['components']['schemas']['Auth2faRecoveryCodesResponse'] ?? null;
        self::assertIsArray($recoverySchema);
        self::assertSame(['recovery_codes'], $recoverySchema['required'] ?? null);

        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $mePayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($mePayload);
        self::assertIsString($mePayload['uuid'] ?? null);
        self::assertIsString($mePayload['id'] ?? null);
        self::assertSame(FixtureUsers::ADMIN_EMAIL, $mePayload['email'] ?? null);
        self::assertIsString($mePayload['display_name'] ?? null);
        self::assertIsBool($mePayload['email_verified'] ?? null);
        self::assertIsBool($mePayload['mfa_enabled'] ?? null);
        self::assertContainsOnly('string', $mePayload['roles'] ?? []);

        $client->request('GET', '/api/v1/auth/me/sessions');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $sessionsPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($sessionsPayload);
        self::assertContainsOnly('array', $sessionsPayload['items'] ?? []);
        self::assertNotEmpty($sessionsPayload['items'] ?? []);
        $session = $sessionsPayload['items'][0];
        self::assertIsString($session['session_id'] ?? null);
        self::assertIsString($session['client_id'] ?? null);
        self::assertIsString($session['created_at'] ?? null);
        self::assertIsString($session['last_used_at'] ?? null);
        self::assertTrue(is_string($session['expires_at'] ?? null) || null === ($session['expires_at'] ?? null));
        self::assertIsBool($session['is_current'] ?? null);
        self::assertTrue(is_string($session['device_label'] ?? null) || null === ($session['device_label'] ?? null));
        self::assertTrue(is_string($session['browser'] ?? null) || null === ($session['browser'] ?? null));
        self::assertTrue(is_string($session['os'] ?? null) || null === ($session['os'] ?? null));
        self::assertTrue(is_string($session['ip_address_last_seen'] ?? null) || null === ($session['ip_address_last_seen'] ?? null));

        $client->request('POST', '/api/v1/auth/2fa/setup');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $setupPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($setupPayload);
        self::assertSame('TOTP', $setupPayload['method'] ?? null);
        self::assertIsString($setupPayload['issuer'] ?? null);
        self::assertIsString($setupPayload['account_name'] ?? null);
        self::assertIsString($setupPayload['secret'] ?? null);
        self::assertIsString($setupPayload['otpauth_uri'] ?? null);
        self::assertTrue(!array_key_exists('qr_svg', $setupPayload) || is_string($setupPayload['qr_svg']));

        $client->jsonRequest('POST', '/api/v1/auth/2fa/enable', [
            'otp_code' => TOTP::create((string) $setupPayload['secret'])->now(),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $enablePayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($enablePayload);
        self::assertSame(true, $enablePayload['mfa_enabled'] ?? null);
        self::assertContainsOnly('string', $enablePayload['recovery_codes'] ?? []);
        self::assertNotEmpty($enablePayload['recovery_codes'] ?? []);

        $client->request('POST', '/api/v1/auth/2fa/recovery-codes/regenerate');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $recoveryPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($recoveryPayload);
        self::assertContainsOnly('string', $recoveryPayload['recovery_codes'] ?? []);
        self::assertNotEmpty($recoveryPayload['recovery_codes'] ?? []);
    }

    public function testAllDeclaredErrorResponsesUseErrorResponseSchema(): void
    {
        $openApi = $this->openApi();
        $paths = $openApi['paths'] ?? [];
        self::assertIsArray($paths);

        foreach ($paths as $path => $operations) {
            if (!is_array($operations)) {
                continue;
            }
            foreach ($operations as $method => $operation) {
                if (!is_array($operation)) {
                    continue;
                }
                $responses = $operation['responses'] ?? [];
                if (!is_array($responses)) {
                    continue;
                }
                foreach ($responses as $status => $response) {
                    if (!is_array($response) || !preg_match('/^[45]\d{2}$/', (string) $status)) {
                        continue;
                    }
                    $content = $response['content']['application/json']['schema'] ?? null;
                    if (!is_array($content)) {
                        continue;
                    }
                    $schemaRef = $content['$ref'] ?? null;
                    self::assertSame(
                        '#/components/schemas/ErrorResponse',
                        $schemaRef,
                        sprintf('OpenAPI path %s %s response %s must reference ErrorResponse.', strtoupper((string) $method), (string) $path, (string) $status)
                    );
                }
            }
        }
    }

    public function testRuntimeContractErrorsMatchOpenApiForCriticalEndpoints(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));

        $client = $this->createAuthenticatedClient();
        $this->ensureAuxiliaryTables();
        $this->seedAsset('33333333-3333-3333-3333-333333333333', AssetState::PROCESSED);

        $cases = [
            [
                'method' => 'POST',
                'url' => '/api/v1/assets/33333333-3333-3333-3333-333333333333/purge',
                'payload' => [],
                'headers' => [
                    'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '33333333-3333-3333-3333-333333333333'),
                    'HTTP_IDEMPOTENCY_KEY' => 'contract-purge-conflict-2',
                ],
                'status' => Response::HTTP_CONFLICT,
                'openapi_path' => '/assets/{uuid}/purge',
                'openapi_method' => 'post',
                'openapi_status' => '409',
            ],
            [
                'method' => 'POST',
                'url' => '/api/v1/auth/lost-password/request',
                'payload' => [],
                'headers' => [],
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'openapi_path' => '/auth/lost-password/request',
                'openapi_method' => 'post',
                'openapi_status' => '422',
            ],
            [
                'method' => 'POST',
                'url' => '/api/v1/auth/login',
                'payload' => ['email' => 'admin@retaia.local', 'password' => 'wrong-password'],
                'headers' => [],
                'status' => Response::HTTP_UNAUTHORIZED,
                'openapi_path' => '/auth/login',
                'openapi_method' => 'post',
                'openapi_status' => '401',
            ],
        ];

        foreach ($cases as $case) {
            $this->assertPathStatusUsesErrorResponse(
                $openApi,
                (string) $case['openapi_path'],
                (string) $case['openapi_method'],
                (string) $case['openapi_status']
            );

            $client->jsonRequest(
                (string) $case['method'],
                (string) $case['url'],
                (array) $case['payload'],
                (array) $case['headers']
            );

            self::assertSame((int) $case['status'], $client->getResponse()->getStatusCode(), (string) $case['url']);
            $payload = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        }
    }

    public function testAuthenticatedSecuredOperationsWithoutRequestBodyMatchDeclaredStatuses(): void
    {
        $openApi = $this->openApi();
        $errorCodes = $this->errorCodes($this->errorSchema($openApi));
        $operations = $this->securedOperationsWithoutRequestBodyAndPathParameters($openApi);

        self::assertNotEmpty($operations, 'Expected at least one secured OpenAPI operation without request body and path params.');

        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        foreach ($operations as $operation) {
            $url = '/api/v1'.(string) $operation['path'];
            $method = strtoupper((string) $operation['method']);
            $email = $this->isAgentScopedOperation((string) $operation['path']) ? FixtureUsers::AGENT_EMAIL : FixtureUsers::ADMIN_EMAIL;
            $this->authenticateClient($client, $email);

            $client->request($method, $url);

            $status = (string) $client->getResponse()->getStatusCode();
            /** @var array<int, string> $declaredStatuses */
            $declaredStatuses = $operation['statuses'];

            self::assertContains(
                $status,
                $declaredStatuses,
                sprintf('Runtime status %s for %s %s is not declared in OpenAPI (%s).', $status, $method, (string) $operation['path'], implode(', ', $declaredStatuses))
            );

            if ((int) $status < 400) {
                continue;
            }

            $statusSchemaRef = $openApi['paths'][(string) $operation['path']][strtolower((string) $operation['method'])]['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null;
            if ($statusSchemaRef !== '#/components/schemas/ErrorResponse') {
                continue;
            }

            $payload = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertErrorPayloadMatchesModel($payload, $errorCodes);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(dirname(__DIR__, 2).'/specs/api/openapi/v1.yaml');

        return $parsed;
    }

    /**
     * @param array<string, mixed> $openApi
     */
    private function assertPathHasIdempotencyHeader(array $openApi, string $path, string $method): void
    {
        $parameters = $openApi['paths'][$path][$method]['parameters'] ?? null;
        self::assertIsArray($parameters, sprintf('OpenAPI path %s %s must define parameters.', strtoupper($method), $path));

        $hasReference = false;
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            if (($parameter['$ref'] ?? null) === '#/components/parameters/IdempotencyKey') {
                $hasReference = true;
                break;
            }
        }

        self::assertTrue($hasReference, sprintf('OpenAPI path %s %s must reference IdempotencyKey.', strtoupper($method), $path));
    }

    /**
     * @param array<string, mixed> $openApi
     */
    private function assertPathStatusUsesErrorResponse(array $openApi, string $path, string $method, string $status): void
    {
        $schemaRef = $openApi['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema']['$ref'] ?? null;
        self::assertSame(
            '#/components/schemas/ErrorResponse',
            $schemaRef,
            sprintf('OpenAPI path %s %s response %s must reference ErrorResponse.', strtoupper($method), $path, $status)
        );
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private function errorSchema(array $openApi): array
    {
        $schema = $openApi['components']['schemas']['ErrorResponse'] ?? null;
        self::assertIsArray($schema);
        self::assertSame(['code', 'message', 'retryable', 'correlation_id'], $schema['required'] ?? null);

        return $schema;
    }

    /**
     * @param array<string, mixed> $errorSchema
     * @return array<int, string>
     */
    private function errorCodes(array $errorSchema): array
    {
        $enum = $errorSchema['properties']['code']['enum'] ?? null;
        self::assertIsArray($enum);

        return array_values(array_map('strval', $enum));
    }

    /**
     * @param mixed $payload
     * @param array<int, string> $errorCodes
     */
    private function assertErrorPayloadMatchesModel(mixed $payload, array $errorCodes): void
    {
        self::assertIsArray($payload);
        self::assertIsString($payload['code'] ?? null);
        self::assertContains((string) $payload['code'], $errorCodes);
        self::assertIsString($payload['message'] ?? null);
        self::assertIsBool($payload['retryable'] ?? null);
        self::assertIsString($payload['correlation_id'] ?? null);
    }

    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();
        $this->resetTwoFactorState(FixtureUsers::ADMIN_EMAIL);

        $this->authenticateClient($client, 'admin@retaia.local');

        return $client;
    }

    private function currentAssetRevisionEtag(KernelBrowser $client, string $uuid): string
    {
        $client->request('GET', '/api/v1/assets/'.$uuid);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $etag = $payload['summary']['revision_etag'] ?? null;
        self::assertIsString($etag);

        return $etag;
    }

    private function ensureAuxiliaryTables(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS app_user (id VARCHAR(32) NOT NULL PRIMARY KEY, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles CLOB NOT NULL, email_verified BOOLEAN NOT NULL DEFAULT 0)');
        $connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_app_user_email ON app_user (email)');
        $this->ensureIdempotencyTable($connection);
        $this->ensureOperationLockTable($connection);
        $this->ensureProcessingJobTable($connection);
        $this->ensureIngestScanTable($connection);
        $this->ensureUnmatchedSidecarTable($connection);
    }

    private function resetTwoFactorState(string $email): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $userId = $connection->fetchOne('SELECT id FROM app_user WHERE email = :email', ['email' => $email]);
        if (!is_string($userId) || $userId === '') {
            return;
        }

        $cache = static::getContainer()->get('cache.app');
        if (method_exists($cache, 'deleteItem')) {
            $cache->deleteItem('auth_2fa_'.sha1($userId));
        }
    }

    private function seedAsset(string $uuid, AssetState $state): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, $uuid) instanceof Asset) {
            return;
        }

        $asset = new Asset($uuid, 'VIDEO', 'contract.mov', $state);
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function seedDetailedAsset(
        string $uuid,
        string $mediaType = 'VIDEO',
        string $filename = 'contract-detailed.mov',
        AssetState $state = AssetState::PROCESSED,
        array $fields = [],
    ): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, $uuid) instanceof Asset) {
            return;
        }

        $asset = new Asset($uuid, $mediaType, $filename, $state);
        $asset->setFields(array_replace([
            'captured_at' => '2026-01-10T12:00:00Z',
            'gps_latitude' => 50.8503,
            'gps_longitude' => 4.3517,
            'location_country' => 'BE',
            'location_city' => 'Brussels',
            'location_label' => 'Grand Place',
            'processing_profile' => 'video_standard',
            'preview_video_url' => 'https://cdn.retaia.test/previews/contract-detailed.mp4',
        ], $fields));
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    private static function assertCacheControlIsPrivateNoStore(string $value): void
    {
        $normalized = array_map('trim', explode(',', $value));
        sort($normalized);

        self::assertSame(['no-store', 'private'], $normalized);
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<int, array{path: string, method: string, statuses: array<int, string>}>
     */
    private function securedOperationsWithoutRequestBodyAndPathParameters(array $openApi): array
    {
        $operations = [];
        $paths = $openApi['paths'] ?? [];
        self::assertIsArray($paths);

        foreach ($paths as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem) || str_contains($path, '{')) {
                continue;
            }

            if ($path === '/auth/logout') {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !is_array($operation)) {
                    continue;
                }

                $normalizedMethod = strtolower($method);
                if (!in_array($normalizedMethod, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    continue;
                }

                $security = $operation['security'] ?? null;
                if (!is_array($security) || $security === []) {
                    continue;
                }

                if (($operation['requestBody']['required'] ?? false) === true) {
                    continue;
                }

                $responses = $operation['responses'] ?? null;
                if (!is_array($responses) || $responses === []) {
                    continue;
                }

                $statuses = [];
                foreach (array_keys($responses) as $status) {
                    if (!is_string($status) && !is_int($status)) {
                        continue;
                    }

                    $normalizedStatus = (string) $status;
                    if (!preg_match('/^[1-5]\d{2}$/', $normalizedStatus)) {
                        continue;
                    }
                    $statuses[] = $normalizedStatus;
                }

                if ($statuses === []) {
                    continue;
                }

                $operations[] = [
                    'path' => $path,
                    'method' => $normalizedMethod,
                    'statuses' => $statuses,
                ];
            }
        }

        return $operations;
    }

    private function isAgentScopedOperation(string $path): bool
    {
        return str_starts_with($path, '/jobs') || $path === '/agents/register';
    }
}
