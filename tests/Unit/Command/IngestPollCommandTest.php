<?php

namespace App\Tests\Unit\Command;

use App\Command\IngestPollCommand;
use App\Ingest\Port\FilePollerInterface;
use App\Ingest\Port\ScanStateStoreInterface;
use App\Ingest\Service\WatchPathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestPollCommandTest extends TestCase
{
    public function testExecuteInJsonModeReturnsDetectedFiles(): void
    {
        $watchDir = sys_get_temp_dir().'/retaia-watch-command-'.bin2hex(random_bytes(4));
        mkdir($watchDir, 0777, true);

        $resolver = new WatchPathResolver($watchDir, '.');
        $poller = new class() implements FilePollerInterface {
            public function poll(int $limit = 100): array
            {
                return [[
                    'path' => 'rush/test.mov',
                    'size' => 42,
                    'mtime' => new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ]];
            }
        };
        $scanStore = new class() implements ScanStateStoreInterface {
            public function recordDetectedFile(string $path, int $size, \DateTimeImmutable $mtime, \DateTimeImmutable $scannedAt): array
            {
                return [
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

            public function markQueued(string $path, \DateTimeImmutable $queuedAt): void
            {
            }

            public function markMissing(string $path, \DateTimeImmutable $at): void
            {
            }
        };

        $command = new IngestPollCommand($resolver, $poller, $scanStore);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($tester->getDisplay(), true);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['count'] ?? null);
        self::assertIsString($payload['items'][0]['path'] ?? null);
        self::assertStringEndsWith('/rush/test.mov', (string) $payload['items'][0]['path']);
        self::assertSame('discovered', $payload['items'][0]['status'] ?? null);
    }
}
