<?php

namespace App\Tests\Functional;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DerivedUploadApiTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    public function testAgentCanUploadAndListDerived(): void
    {
        $client = $this->login('agent@retaia.local');
        $this->seedAsset();

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/init', [
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', 'proxy-content'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $init = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($init);
        $uploadId = $init['upload_id'] ?? null;
        self::assertIsString($uploadId);

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/part', [
            'upload_id' => $uploadId,
            'part_number' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/complete', [
            'upload_id' => $uploadId,
            'total_parts' => 1,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $complete = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('proxy_video', $complete['kind'] ?? null);

        $client->request('GET', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $listPayload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $listPayload['items'] ?? []);

        $client->request('GET', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/proxy_video');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testNonAgentCannotUploadDerived(): void
    {
        $client = $this->login('admin@retaia.local');
        $this->seedAsset();

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/init', [
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 1024,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('FORBIDDEN_ACTOR', $payload['code'] ?? null);
    }

    public function testCannotCompleteUploadWithoutAllParts(): void
    {
        $client = $this->login('agent@retaia.local');
        $this->seedAsset();

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/init', [
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 1024,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $init = json_decode((string) $client->getResponse()->getContent(), true);
        $uploadId = (string) ($init['upload_id'] ?? '');

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/complete', [
            'upload_id' => $uploadId,
            'total_parts' => 2,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('STATE_CONFLICT', $payload['code'] ?? null);
    }

    public function testUploadValidationAndNotFoundBranches(): void
    {
        $client = $this->login('agent@retaia.local');
        $this->seedAsset();

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/part', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $validationPart = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $validationPart['code'] ?? null);

        $client->jsonRequest('POST', '/api/v1/assets/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/derived/upload/complete', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $validationComplete = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('VALIDATION_FAILED', $validationComplete['code'] ?? null);

        $unknownAsset = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
        $client->jsonRequest('POST', "/api/v1/assets/{$unknownAsset}/derived/upload/init", [
            'kind' => 'proxy_video',
            'content_type' => 'video/mp4',
            'size_bytes' => 1024,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', "/api/v1/assets/{$unknownAsset}/derived");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('GET', "/api/v1/assets/{$unknownAsset}/derived/proxy_video");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function seedAsset(): void
    {
        $this->ensureDerivedSchema();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->find(Asset::class, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
        if ($existing instanceof Asset) {
            return;
        }

        $asset = new Asset('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'VIDEO', 'proxy-source.mov', AssetState::PROCESSED);
        $entityManager->persist($asset);
        $entityManager->flush();
    }

    private function ensureDerivedSchema(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS derived_upload_session (
                upload_id VARCHAR(24) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                kind VARCHAR(64) NOT NULL,
                content_type VARCHAR(128) NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 VARCHAR(64) DEFAULT NULL,
                status VARCHAR(16) NOT NULL,
                parts_count INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )'
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS asset_derived_file (
                id VARCHAR(16) PRIMARY KEY NOT NULL,
                asset_uuid VARCHAR(36) NOT NULL,
                kind VARCHAR(64) NOT NULL,
                content_type VARCHAR(128) NOT NULL,
                size_bytes INTEGER NOT NULL,
                sha256 VARCHAR(64) DEFAULT NULL,
                storage_path VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }

    private function login(string $email): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'change-me',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $token = $payload['access_token'] ?? null;
        self::assertIsString($token);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        return $client;
    }
}
