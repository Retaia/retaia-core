<?php

namespace App\Tests\Unit\Support;

use App\Api\Service\AgentSignature\AgentPublicKeyRecord;
use App\Api\Service\AgentSignature\AgentSignatureNonceRecord;
use App\Auth\AuthClientRegistryEntry;
use App\Auth\AuthMcpChallenge;
use App\Auth\TechnicalAccessTokenRecord;
use App\Asset\AssetRevisionTag;
use App\Domain\AuthClient\ClientKind;
use App\Entity\Asset;
use App\Entity\WebAuthnDevice;
use App\Observability\MetricName;
use App\Security\ApiClientPrincipal;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageFile;
use App\Storage\BusinessStorageInterface;
use PHPUnit\Framework\TestCase;

final class ValueAndRecordObjectsTest extends TestCase
{
    public function testAssetRevisionTagBuildsDeterministicValues(): void
    {
        $asset = new Asset(
            uuid: 'asset-1',
            mediaType: 'VIDEO',
            filename: 'clip.mp4',
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $fromAsset = AssetRevisionTag::fromAsset($asset);
        $fromPayload = AssetRevisionTag::fromPayload([
            'uuid' => 'asset-1',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);

        self::assertSame($fromAsset, $fromPayload);
        self::assertNull(AssetRevisionTag::fromPayload(['uuid' => 'asset-1']));
    }

    public function testApiClientPrincipalMapsRolesFromClientKind(): void
    {
        self::assertSame(['ROLE_AGENT'], (new ApiClientPrincipal('c1', ClientKind::AGENT))->getRoles());
        self::assertSame(['ROLE_MCP'], (new ApiClientPrincipal('c2', ClientKind::MCP))->getRoles());
        self::assertSame([], (new ApiClientPrincipal('c3', 'other'))->getRoles());
        self::assertSame('client:c1', (new ApiClientPrincipal('c1', ClientKind::AGENT))->getUserIdentifier());
    }

    public function testBusinessStorageDefinitionRequiresNonEmptyId(): void
    {
        $storage = $this->createMock(BusinessStorageInterface::class);
        $definition = new BusinessStorageDefinition('nas-main', $storage, false);

        self::assertSame('nas-main', $definition->id);
        self::assertFalse($definition->ingestEnabled);

        $this->expectException(\InvalidArgumentException::class);
        new BusinessStorageDefinition('   ', $storage);
    }

    public function testBusinessStorageFileExposesReadonlyPayload(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $file = new BusinessStorageFile('ARCHIVE/clip.mp4', 42, $now);

        self::assertSame('ARCHIVE/clip.mp4', $file->path);
        self::assertSame(42, $file->size);
        self::assertSame($now, $file->lastModified);
    }

    public function testMetricNameBuildersUseStablePrefixes(): void
    {
        self::assertSame('auth.device.poll.status.SUCCESS', MetricName::authDevicePollStatus('SUCCESS'));
        self::assertSame('lock.acquire.failed.move', MetricName::lockAcquireFailed('move'));
        self::assertSame('lock.acquire.success.move', MetricName::lockAcquireSuccess('move'));
        self::assertSame('lock.release.move', MetricName::lockRelease('move'));
        self::assertSame('lock.active.detected.move', MetricName::lockActiveDetectedByType('move'));
        self::assertSame('lock.watchdog.released.move', MetricName::lockWatchdogReleased('move'));
        self::assertSame('api.error.NOT_FOUND', MetricName::apiError('NOT_FOUND'));
    }

    public function testAgentSignatureNonceRecordRoundTripsAndValidatesInput(): void
    {
        $record = AgentSignatureNonceRecord::create('agent-1', 'nonce-1', 60, 100);

        self::assertInstanceOf(AgentSignatureNonceRecord::class, $record);
        self::assertSame([
            'nonce_key' => hash('sha256', 'agent-1|nonce-1'),
            'agent_id' => 'agent-1',
            'expires_at' => 160,
            'consumed_at' => 100,
        ], $record?->toRow());
        self::assertNull(AgentSignatureNonceRecord::create('', 'nonce', 60, 100));
        self::assertNull(AgentSignatureNonceRecord::fromArray(['nonce_key' => '', 'agent_id' => 'x']));
    }

    public function testAgentPublicKeyRecordRoundTripsAndNormalizesFingerprint(): void
    {
        $record = AgentPublicKeyRecord::fromArray([
            'agent_id' => 'agent-1',
            'openpgp_fingerprint' => 'aa bb cc dd ee ff 00 11 22 33 44 55 66 77 88 99 aa bb cc dd',
            'openpgp_public_key' => 'PUBLIC KEY',
            'updated_at' => 123,
        ]);

        self::assertInstanceOf(AgentPublicKeyRecord::class, $record);
        self::assertSame('AABBCCDDEEFF00112233445566778899AABBCCDD', $record?->fingerprint);
        self::assertSame([
            'agent_id' => 'agent-1',
            'openpgp_fingerprint' => 'AABBCCDDEEFF00112233445566778899AABBCCDD',
            'openpgp_public_key' => 'PUBLIC KEY',
            'updated_at' => 123,
        ], $record?->toRow());
        self::assertNull(AgentPublicKeyRecord::fromArray(['agent_id' => 'agent-1']));
    }

    public function testTechnicalAccessTokenRecordParsesRowsAndSyncs(): void
    {
        $record = TechnicalAccessTokenRecord::fromArray([
            'client_id' => 'client-1',
            'access_token' => 'token-1',
            'client_kind' => 'mcp',
            'issued_at' => 123,
        ]);

        self::assertInstanceOf(TechnicalAccessTokenRecord::class, $record);
        self::assertSame('client-1', $record?->clientId);
        self::assertNull(TechnicalAccessTokenRecord::fromArray(['client_id' => '']));

        $updated = new TechnicalAccessTokenRecord('client-1', 'token-2', 'agent', 456);
        $record?->syncFrom($updated);

        self::assertSame('token-2', $record?->accessToken);
        self::assertSame('agent', $record?->clientKind);
        self::assertSame(456, $record?->issuedAt);
    }

    public function testAuthClientRegistryEntryParsesRowsAndSyncs(): void
    {
        $entry = AuthClientRegistryEntry::fromArray([
            'client_id' => 'client-1',
            'client_kind' => 'mcp',
            'secret_key' => 'secret-1',
            'client_label' => 'Label',
        ]);

        self::assertInstanceOf(AuthClientRegistryEntry::class, $entry);
        self::assertSame('secret-1', $entry?->secretKey);
        self::assertNull(AuthClientRegistryEntry::fromArray(['client_kind' => 'mcp']));

        $updated = new AuthClientRegistryEntry('client-1', 'agent', 'secret-2', 'New Label', 'KEY', 'FPR', '2026', '2027');
        $entry?->syncFrom($updated);

        self::assertSame('agent', $entry?->clientKind);
        self::assertSame('secret-2', $entry?->secretKey);
        self::assertSame('FPR', $entry?->openPgpFingerprint);
    }

    public function testAuthMcpChallengeParsesRowsAndSyncs(): void
    {
        $challenge = AuthMcpChallenge::fromArray([
            'challenge_id' => 'challenge-1',
            'client_id' => 'client-1',
            'openpgp_fingerprint' => 'FPR',
            'challenge' => 'payload',
            'expires_at' => 100,
            'used' => 0,
            'used_at' => null,
        ]);

        self::assertInstanceOf(AuthMcpChallenge::class, $challenge);
        self::assertFalse($challenge?->used);
        self::assertNull(AuthMcpChallenge::fromArray(['challenge_id' => '']));

        $updated = new AuthMcpChallenge('challenge-1', 'client-2', 'FPR2', 'payload-2', 200, true, 150);
        $challenge?->syncFrom($updated);

        self::assertSame('client-2', $challenge?->clientId);
        self::assertTrue($challenge?->used ?? false);
        self::assertSame(150, $challenge?->usedAt);
    }

    public function testWebauthnDeviceExposesStoredFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $device = new WebAuthnDevice('device-1', 'user-1', 'credential-1', 'Laptop', 'fingerprint-1', $createdAt);

        self::assertSame('device-1', $device->getId());
        self::assertSame('user-1', $device->getUserId());
        self::assertSame('credential-1', $device->getCredentialId());
        self::assertSame('Laptop', $device->getDeviceLabel());
        self::assertSame('fingerprint-1', $device->getWebauthnFingerprint());
        self::assertSame($createdAt, $device->getCreatedAt());
    }
}
