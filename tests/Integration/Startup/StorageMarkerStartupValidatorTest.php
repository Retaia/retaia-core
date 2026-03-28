<?php

namespace App\Tests\Integration\Startup;

use App\Storage\BusinessStorageConfig;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageRegistry;
use App\Storage\LocalBusinessStorageFactory;
use App\Startup\StorageMarkerStartupException;
use App\Startup\StorageMarkerStartupValidator;
use PHPUnit\Framework\TestCase;

final class StorageMarkerStartupValidatorTest extends TestCase
{
    public function testMissingMarkerIsCreatedAndValidated(): void
    {
        $root = $this->makeRoot('marker-create');
        $validator = $this->validator($root.'/INBOX');

        $validator->validateStartup();

        $markerPath = $root.'/.retaia';
        self::assertFileExists($markerPath);
        $payload = json_decode((string) file_get_contents($markerPath), true);
        self::assertSame(1, $payload['version'] ?? null);
        self::assertSame('nas-main', $payload['storage_id'] ?? null);
        self::assertSame('INBOX', $payload['paths']['inbox'] ?? null);
        self::assertSame('ARCHIVE', $payload['paths']['archive'] ?? null);
        self::assertSame('REJECTS', $payload['paths']['rejects'] ?? null);
    }

    public function testInvalidJsonMarkerFailsFast(): void
    {
        $root = $this->makeRoot('marker-invalid-json');
        file_put_contents($root.'/.retaia', '{invalid}');

        $validator = $this->validator($root.'/INBOX');
        $this->expectException(StorageMarkerStartupException::class);
        $this->expectExceptionMessage('[CORE_STORAGE_MARKER_JSON_INVALID]');
        $validator->validateStartup();
    }

    public function testStorageIdMismatchFailsFast(): void
    {
        $root = $this->makeRoot('marker-mismatch');
        file_put_contents($root.'/.retaia', json_encode([
            'version' => 1,
            'storage_id' => 'wrong-storage',
            'paths' => [
                'inbox' => 'INBOX',
                'archive' => 'ARCHIVE',
                'rejects' => 'REJECTS',
            ],
        ], JSON_PRETTY_PRINT));

        $validator = $this->validator($root.'/INBOX');
        $this->expectException(StorageMarkerStartupException::class);
        $this->expectExceptionMessage('[CORE_STORAGE_MARKER_STORAGE_ID_MISMATCH]');
        $validator->validateStartup();
    }

    public function testUnsupportedMarkerVersionFailsFast(): void
    {
        $root = $this->makeRoot('marker-version');
        file_put_contents($root.'/.retaia', json_encode([
            'version' => 2,
            'storage_id' => 'nas-main',
            'paths' => [
                'inbox' => 'INBOX',
                'archive' => 'ARCHIVE',
                'rejects' => 'REJECTS',
            ],
        ], JSON_PRETTY_PRINT));

        $validator = $this->validator($root.'/INBOX');
        $this->expectException(StorageMarkerStartupException::class);
        $this->expectExceptionMessage('[CORE_STORAGE_MARKER_SCHEMA_UPGRADE_FAILED]');
        $validator->validateStartup();
    }

    private function validator(string $watchPath): StorageMarkerStartupValidator
    {
        return new StorageMarkerStartupValidator(
            new BusinessStorageRegistry('nas-main', [
                new BusinessStorageDefinition(
                    'nas-main',
                    (new LocalBusinessStorageFactory(BusinessStorageConfig::fromConfiguredWatchPath('/', $watchPath)))->create(),
                    true,
                ),
            ])
        );
    }

    private function makeRoot(string $prefix): string
    {
        $root = sys_get_temp_dir().'/retaia-'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($root.'/INBOX', 0777, true);
        mkdir($root.'/ARCHIVE', 0777, true);
        mkdir($root.'/REJECTS', 0777, true);

        return $root;
    }
}
