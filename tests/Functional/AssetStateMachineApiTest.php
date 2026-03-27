<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use App\Tests\Support\ApiAuthClientTrait;
use App\Tests\Support\FunctionalSchemaTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AssetStateMachineApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;
    use ApiAuthClientTrait;
    use FunctionalSchemaTrait;

    public function testReopenFromArchivedTransitionsToDecisionPending(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/33333333-3333-3333-3333-333333333333/reopen', [], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '33333333-3333-3333-3333-333333333333'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECISION_PENDING', $payload['state'] ?? null);
        self::assertSame($payload['revision_etag'] ?? null, $client->getResponse()->headers->get('ETag'));
    }

    public function testReprocessTransitionsArchivedAssetBackToReady(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('POST', '/api/v1/assets/33333333-3333-3333-3333-333333333333/reprocess', [], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '33333333-3333-3333-3333-333333333333'),
            'HTTP_IDEMPOTENCY_KEY' => 'asset-reprocess-ok-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('READY', $payload['state'] ?? null);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $asset = $entityManager->find(Asset::class, '33333333-3333-3333-3333-333333333333');
        self::assertInstanceOf(Asset::class, $asset);
        self::assertSame('4', (string) ($asset->getFields()['review_processing_version'] ?? null));
        self::assertFalse((bool) ($asset->getFields()['facts_done'] ?? true));
        self::assertFalse((bool) ($asset->getFields()['proxy_done'] ?? true));
        self::assertFalse((bool) ($asset->getFields()['thumbs_done'] ?? true));
    }

    public function testReprocessReturnsNotFoundForUnknownAsset(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/v1/assets/00000000-0000-0000-0000-000000000000/reprocess', [], [
            'HTTP_IDEMPOTENCY_KEY' => 'asset-reprocess-missing-1',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
    }

    public function testAssetMutationsRejectMissingIfMatchAcrossProtectedRoutes(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $cases = [
            ['method' => 'PATCH', 'url' => '/api/v1/assets/11111111-1111-1111-1111-111111111111', 'payload' => ['notes' => 'missing if-match']],
            ['method' => 'POST', 'url' => '/api/v1/assets/33333333-3333-3333-3333-333333333333/reopen', 'payload' => []],
            ['method' => 'POST', 'url' => '/api/v1/assets/33333333-3333-3333-3333-333333333333/reprocess', 'payload' => []],
        ];

        foreach ($cases as $index => $case) {
            $headers = [];
            if (($case['method'] ?? null) === 'POST') {
                $headers['HTTP_IDEMPOTENCY_KEY'] = sprintf('asset-precondition-required-%d', $index + 1);
            }

            $client->jsonRequest((string) $case['method'], (string) $case['url'], (array) $case['payload'], $headers);

            $this->assertPreconditionError($client, Response::HTTP_PRECONDITION_REQUIRED, 'PRECONDITION_REQUIRED');
        }
    }

    public function testAssetMutationsRejectStaleIfMatchAcrossProtectedRoutes(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $cases = [
            [
                'method' => 'PATCH',
                'url' => '/api/v1/assets/11111111-1111-1111-1111-111111111111',
                'payload' => ['notes' => 'stale if-match'],
                'uuid' => '11111111-1111-1111-1111-111111111111',
            ],
            [
                'method' => 'POST',
                'url' => '/api/v1/assets/33333333-3333-3333-3333-333333333333/reopen',
                'payload' => [],
                'uuid' => '33333333-3333-3333-3333-333333333333',
            ],
            [
                'method' => 'POST',
                'url' => '/api/v1/assets/33333333-3333-3333-3333-333333333333/reprocess',
                'payload' => [],
                'uuid' => '33333333-3333-3333-3333-333333333333',
            ],
        ];

        foreach ($cases as $index => $case) {
            $headers = [
                'HTTP_IF_MATCH' => '"stale-revision-etag"',
            ];
            if (($case['method'] ?? null) === 'POST') {
                $headers['HTTP_IDEMPOTENCY_KEY'] = sprintf('asset-precondition-failed-%d', $index + 1);
            }

            $client->jsonRequest((string) $case['method'], (string) $case['url'], (array) $case['payload'], $headers);

            $this->assertPreconditionError(
                $client,
                Response::HTTP_PRECONDITION_FAILED,
                'PRECONDITION_FAILED',
                (string) $case['uuid']
            );
        }
    }

    public function testPatchUpdatesTagsNotesAndFields(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'tags' => ['updated', 'updated', 'news'],
            'notes' => 'patched note',
            'fields' => ['camera' => 'fx3', 'fps' => 25],
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['updated', 'news'], $payload['tags'] ?? []);
        self::assertSame('patched note', $payload['notes'] ?? null);
        self::assertSame('fx3', $payload['fields']['camera'] ?? null);
        self::assertSame(120, $payload['fields']['duration'] ?? null);
        self::assertArrayNotHasKey('projects', $payload['fields'] ?? []);
    }

    public function testGetAssetExposesProjectsOutsideFields(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets/11111111-1111-1111-1111-111111111111');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('Project Alpha', $payload['projects'][0]['project_name'] ?? null);
        self::assertSame('Main edit', $payload['projects'][0]['description'] ?? null);
        self::assertArrayNotHasKey('projects', $payload['fields'] ?? []);
    }

    public function testGetAssetReturnsSpecHeadersAndMetadataShape(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets/11111111-1111-1111-1111-111111111111');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertCacheControlIsPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame($payload['summary']['revision_etag'] ?? null, $client->getResponse()->headers->get('ETag'));
        self::assertSame(true, $payload['summary']['has_preview'] ?? null);
        self::assertArrayNotHasKey('has_proxy', $payload['summary'] ?? []);
        self::assertSame(50.8503, $payload['gps_latitude'] ?? null);
        self::assertSame(4.3517, $payload['gps_longitude'] ?? null);
        self::assertSame('BE', $payload['location_country'] ?? null);
        self::assertSame('Brussels', $payload['location_city'] ?? null);
        self::assertSame('Grand Place', $payload['location_label'] ?? null);
        self::assertSame('video_standard', $payload['processing']['processing_profile'] ?? null);
        self::assertSame('https://cdn.retaia.test/previews/rush-001.mp4', $payload['derived']['preview_video_url'] ?? null);
    }

    public function testPatchUpdatesProjectsAndDeduplicatesByProjectId(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'projects' => [
                [
                    'project_id' => 'proj-beta',
                    'project_name' => 'Project Beta',
                    'created_at' => '2026-02-02T08:30:00Z',
                    'description' => 'Secondary use',
                ],
                [
                    'project_id' => 'proj-beta',
                    'project_name' => 'Project Beta Duplicate',
                    'created_at' => '2026-02-02T08:31:00Z',
                ],
                [
                    'project_id' => 'proj-gamma',
                    'project_name' => 'Project Gamma',
                    'created_at' => '2026-03-02T08:30:00Z',
                ],
            ],
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('proj-beta', $payload['projects'][0]['project_id'] ?? null);
        self::assertSame('Secondary use', $payload['projects'][0]['description'] ?? null);
        self::assertCount(2, $payload['projects'] ?? []);
        self::assertArrayNotHasKey('projects', $payload['fields'] ?? []);

        $client->request('GET', '/api/v1/assets/11111111-1111-1111-1111-111111111111');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $detailPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $detailPayload['projects'] ?? []);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $asset = $entityManager->find(Asset::class, '11111111-1111-1111-1111-111111111111');
        self::assertInstanceOf(Asset::class, $asset);
        self::assertCount(2, $asset->getFields()['projects'] ?? []);
    }

    public function testPatchRejectsInvalidProjectsPayload(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'projects' => [
                [
                    'project_id' => 'proj-invalid',
                    'created_at' => 'not-a-date',
                ],
            ],
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testPatchSupportsMutableAssetMetadataFields(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'captured_at' => '2026-03-10T10:11:12Z',
            'gps_latitude' => 48.8566,
            'gps_longitude' => 2.3522,
            'gps_altitude_m' => 35.5,
            'gps_altitude_relative_m' => 4.0,
            'gps_altitude_absolute_m' => 39.5,
            'location_country' => 'FR',
            'location_city' => 'Paris',
            'location_label' => 'Studio',
            'processing_profile' => 'audio_voice',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('2026-03-10T10:11:12Z', $payload['fields']['captured_at'] ?? null);
        self::assertSame(48.8566, $payload['fields']['gps_latitude'] ?? null);
        self::assertSame(2.3522, $payload['fields']['gps_longitude'] ?? null);
        self::assertSame(35.5, $payload['fields']['gps_altitude_m'] ?? null);
        self::assertEquals(4.0, $payload['fields']['gps_altitude_relative_m'] ?? null);
        self::assertSame(39.5, $payload['fields']['gps_altitude_absolute_m'] ?? null);
        self::assertSame('FR', $payload['fields']['location_country'] ?? null);
        self::assertSame('Paris', $payload['fields']['location_city'] ?? null);
        self::assertSame('Studio', $payload['fields']['location_label'] ?? null);
        self::assertSame('audio_voice', $payload['fields']['processing_profile'] ?? null);

        $client->request('GET', '/api/v1/assets/11111111-1111-1111-1111-111111111111');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $detailPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(48.8566, $detailPayload['gps_latitude'] ?? null);
        self::assertSame(2.3522, $detailPayload['gps_longitude'] ?? null);
        self::assertSame('FR', $detailPayload['location_country'] ?? null);
        self::assertSame('Paris', $detailPayload['location_city'] ?? null);
        self::assertSame('Studio', $detailPayload['location_label'] ?? null);
        self::assertSame('audio_voice', $detailPayload['processing']['processing_profile'] ?? null);
    }

    public function testPatchRejectsInvalidNotesType(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'notes' => 123,
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testPatchRejectsInvalidTagsPayload(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'tags' => ['valid', 1],
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testPatchRejectsUnsupportedStateMutation(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/11111111-1111-1111-1111-111111111111', [
            'state' => 'READY',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '11111111-1111-1111-1111-111111111111'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testPatchAppliesSupportedStateTransition(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/33333333-3333-3333-3333-333333333333', [
            'state' => 'DECISION_PENDING',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '33333333-3333-3333-3333-333333333333'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('DECISION_PENDING', $payload['state'] ?? null);
    }

    public function testPatchReturnsConflictForInvalidStateTransition(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->jsonRequest('PATCH', '/api/v1/assets/22222222-2222-2222-2222-222222222222', [
            'state' => 'ARCHIVED',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '22222222-2222-2222-2222-222222222222'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testPatchReturnsNotFoundForUnknownAsset(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('PATCH', '/api/v1/assets/00000000-0000-0000-0000-000000000000', [
            'notes' => 'anything',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('NOT_FOUND', $payload['code'] ?? null);
    }

    public function testPatchReturnsGoneForPurgedAsset(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->seedPurgedAsset();

        $client->jsonRequest('PATCH', '/api/v1/assets/44444444-4444-4444-4444-444444444444', [
            'notes' => 'should fail',
        ], [
            'HTTP_IF_MATCH' => $this->currentAssetRevisionEtag($client, '44444444-4444-4444-4444-444444444444'),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testListAssetsFiltersByState(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?state=PROCESSED&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][0]['uuid'] ?? null);
    }

    public function testListAssetsAppliesSpecFiltersAndHeaders(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?tags=wedding&tags_mode=AND&has_preview=true&location_country=BE&location_city=Brussels&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertCacheControlIsPrivateNoStore((string) $client->getResponse()->headers->get('Cache-Control'));
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('11111111-1111-1111-1111-111111111111', $payload['items'][0]['uuid'] ?? null);
        self::assertSame(true, $payload['items'][0]['has_preview'] ?? null);
        self::assertArrayNotHasKey('has_proxy', $payload['items'][0] ?? []);
        self::assertArrayHasKey('next_cursor', $payload);
    }

    public function testListAssetsSupportsStateArrays(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?state=PROCESSED,ARCHIVED&sort=name&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(2, $payload['items'] ?? []);
        self::assertSame('33333333-3333-3333-3333-333333333333', $payload['items'][0]['uuid'] ?? null);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][1]['uuid'] ?? null);
    }

    public function testListAssetsSupportsGeoBboxFilter(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?geo_bbox=4.30,50.80,4.45,50.92&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('11111111-1111-1111-1111-111111111111', $payload['items'][0]['uuid'] ?? null);
    }

    public function testListAssetsSupportsCursorPagination(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?sort=name&limit=1');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('33333333-3333-3333-3333-333333333333', $payload['items'][0]['uuid'] ?? null);
        self::assertIsString($payload['next_cursor'] ?? null);

        $cursor = (string) $payload['next_cursor'];
        $client->request('GET', '/api/v1/assets?sort=name&limit=1&cursor='.rawurlencode($cursor));

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $secondPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($secondPayload);
        self::assertCount(1, $secondPayload['items'] ?? []);
        self::assertSame('11111111-1111-1111-1111-111111111111', $secondPayload['items'][0]['uuid'] ?? null);
    }

    public function testListAssetsSupportsCapturedAtRangeFilters(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?captured_at_from=2026-01-15T00:00:00Z&captured_at_to=2026-01-31T23:59:59Z');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][0]['uuid'] ?? null);
    }

    public function testListAssetsSupportsSortingByDuration(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?sort=duration&limit=10');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('22222222-2222-2222-2222-222222222222', $payload['items'][0]['uuid'] ?? null);
        self::assertSame('11111111-1111-1111-1111-111111111111', $payload['items'][1]['uuid'] ?? null);
    }

    public function testListAssetsReturnsValidationFailedForInvalidSort(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?sort=oops');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testListAssetsReturnsValidationFailedForInvalidGeoBbox(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $client->request('GET', '/api/v1/assets?geo_bbox=4.50,50.80,4.30,50.92');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    public function testListAssetsReturnsValidationFailedForCursorMismatch(): void
    {
        $client = $this->createAuthenticatedClient(true);

        $cursor = rtrim(strtr(base64_encode(json_encode(['offset' => 1, 'context_hash' => 'wrong'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $client->request('GET', '/api/v1/assets?limit=1&cursor='.rawurlencode($cursor));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $payload['code'] ?? null);
    }

    private function createAuthenticatedClient(bool $seedAssets = false): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->ensureAuxiliaryTables();

        if ($seedAssets) {
            $this->seedAssets();
        }

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
        $connection = self::getContainer()->get(Connection::class);
        $this->ensureOperationLockTable($connection);
        $this->ensureIdempotencyTable($connection);
    }

    private function seedAssets(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $asset1 = new Asset('11111111-1111-1111-1111-111111111111', 'VIDEO', 'rush-001.mov', AssetState::DECISION_PENDING);
        $asset1->setTags(['wedding']);
        $asset1->setNotes('first review');
        $asset1->setFields([
            'camera' => 'a7s',
            'captured_at' => '2026-01-10T12:00:00Z',
            'duration' => 120,
            'gps_latitude' => 50.8503,
            'gps_longitude' => 4.3517,
            'gps_altitude_m' => 18.0,
            'gps_altitude_relative_m' => 3.0,
            'gps_altitude_absolute_m' => 21.0,
            'location_country' => 'BE',
            'location_city' => 'Brussels',
            'location_label' => 'Grand Place',
            'processing_profile' => 'video_standard',
            'preview_video_url' => 'https://cdn.retaia.test/previews/rush-001.mp4',
            'projects' => [
                [
                    'project_id' => 'proj-alpha',
                    'project_name' => 'Project Alpha',
                    'created_at' => '2026-01-05T09:00:00Z',
                    'description' => 'Main edit',
                ],
            ],
            'suggestions' => [
                'suggested_tags' => ['wedding', 'ceremony'],
            ],
        ]);

        $asset2 = new Asset('22222222-2222-2222-2222-222222222222', 'AUDIO', 'voice-001.wav', AssetState::PROCESSED);
        $asset2->setFields([
            'captured_at' => '2026-01-20T12:00:00Z',
            'duration' => 30,
            'gps_latitude' => 48.8566,
            'gps_longitude' => 2.3522,
            'location_country' => 'FR',
            'location_city' => 'Paris',
        ]);
        $asset3 = new Asset('33333333-3333-3333-3333-333333333333', 'PHOTO', 'archive-001.jpg', AssetState::ARCHIVED);
        $asset3->setFields([
            'captured_at' => '2026-02-15T12:00:00Z',
            'review_processing_version' => '3',
            'facts_done' => true,
            'proxy_done' => true,
            'thumbs_done' => true,
        ]);

        $entityManager->persist($asset1);
        $entityManager->persist($asset2);
        $entityManager->persist($asset3);
        $entityManager->flush();
    }

    private function seedPurgedAsset(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        if ($entityManager->find(Asset::class, '44444444-4444-4444-4444-444444444444') instanceof Asset) {
            return;
        }

        $asset = new Asset('44444444-4444-4444-4444-444444444444', 'VIDEO', 'purged.mov', AssetState::PURGED);
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    private function assertPreconditionError(KernelBrowser $client, int $status, string $code, ?string $uuid = null): void
    {
        self::assertResponseStatusCodeSame($status);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame($code, $payload['code'] ?? null);
        self::assertIsArray($payload['details'] ?? null);
        self::assertIsString($payload['details']['current_revision_etag'] ?? null);
        self::assertIsString($payload['details']['current_state'] ?? null);

        if (is_string($uuid)) {
            self::assertSame(
                $this->currentAssetRevisionEtag($client, $uuid),
                $payload['details']['current_revision_etag'] ?? null
            );
        }
    }

    private static function assertCacheControlIsPrivateNoStore(string $value): void
    {
        $normalized = array_map('trim', explode(',', $value));
        sort($normalized);

        self::assertSame(['no-store', 'private'], $normalized);
    }
}
