<?php

namespace App\Tests\Integration\Command;

use App\Command\IngestPollCommand;
use App\Ingest\Port\FilePollerInterface;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Storage\BusinessStorageDefinition;
use App\Storage\BusinessStorageRegistry;
use App\Storage\BusinessStorageRegistryInterface;
use App\Storage\LocalBusinessStorageFactory;
use App\Storage\BusinessStorageConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestPollCommandTest extends TestCase
{
    public function testExecuteInJsonModeReturnsDetectedFiles(): void
    {
        $watchDir = sys_get_temp_dir().'/retaia-watch-command-'.bin2hex(random_bytes(4));
        mkdir($watchDir, 0777, true);

        $poller = new class() implements FilePollerInterface {
            public function poll(int $limit = 100): array
            {
                return [[
                    'storage_id' => 'nas-main',
                    'path' => 'rush/test.mov',
                    'size' => 42,
                    'mtime' => new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ]];
            }
        };
        $scanStore = new class() implements ScanStateStoreInterface {
            public function recordDetectedFile(string $storageId, string $path, int $size, \DateTimeImmutable $mtime, \DateTimeImmutable $scannedAt): array
            {
                return [
                    'storage_id' => $storageId,
                    'path' => $path,
                    'size' => $size,
                    'mtime' => $mtime,
                    'stable_count' => 1,
                    'status' => 'discovered',
                    'first_seen_at' => $scannedAt,
                    'last_seen_at' => $scannedAt,
                ];
            }

            public function listStableFiles(int $limit = 100): array
            {
                return [];
            }

            public function markQueued(string $storageId, string $path, \DateTimeImmutable $queuedAt): void
            {
            }

            public function markMissing(string $storageId, string $path, \DateTimeImmutable $at): void
            {
            }
        };

        $command = new IngestPollCommand(
            $this->storageRegistry($watchDir),
            $poller,
            $scanStore
        );
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['count'] ?? null);
        self::assertIsString($payload['items'][0]['path'] ?? null);
        self::assertSame('nas-main', $payload['items'][0]['storage_id'] ?? null);
        self::assertStringEndsWith('/rush/test.mov', (string) $payload['items'][0]['path']);
        self::assertSame('discovered', $payload['items'][0]['status'] ?? null);
    }

    private function storageRegistry(string $watchDir): BusinessStorageRegistryInterface
    {
        $config = BusinessStorageConfig::fromConfiguredWatchPath('/', $watchDir);

        return new BusinessStorageRegistry('nas-main', [
            new BusinessStorageDefinition('nas-main', (new LocalBusinessStorageFactory($config))->create(), true),
        ]);
    }
}
