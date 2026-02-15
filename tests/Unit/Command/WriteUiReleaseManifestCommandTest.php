<?php

namespace App\Tests\Unit\Command;

use App\Command\WriteUiReleaseManifestCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class WriteUiReleaseManifestCommandTest extends TestCase
{
    public function testExecuteWritesManifestJson(): void
    {
        $workspace = sys_get_temp_dir().'/retaia-manifest-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace);
        $outputFile = $workspace.'/public/releases/latest.json';

        try {
            $command = new WriteUiReleaseManifestCommand($workspace);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([
                '--ui-version' => '1.2.3',
                '--asset-url' => 'https://downloads.example.com/retaia-ui-1.2.3.zip',
                '--sha256' => str_repeat('a', 64),
                '--notes-url' => 'https://example.com/releases/1.2.3',
                '--channel' => 'stable',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($outputFile);

            $payload = json_decode((string) file_get_contents($outputFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('1.2.3', $payload['version'] ?? null);
            self::assertSame('stable', $payload['channel'] ?? null);
            self::assertSame('https://downloads.example.com/retaia-ui-1.2.3.zip', $payload['asset_url'] ?? null);
            self::assertSame(str_repeat('a', 64), $payload['sha256'] ?? null);
            self::assertSame('https://example.com/releases/1.2.3', $payload['notes_url'] ?? null);
            self::assertNotSame('', (string) ($payload['generated_at'] ?? ''));
        } finally {
            $filesystem->remove($workspace);
        }
    }

    public function testExecuteRejectsInvalidSha256(): void
    {
        $workspace = sys_get_temp_dir().'/retaia-manifest-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($workspace);

        try {
            $command = new WriteUiReleaseManifestCommand($workspace);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([
                '--ui-version' => '1.2.3',
                '--asset-url' => 'https://downloads.example.com/retaia-ui-1.2.3.zip',
                '--sha256' => 'not-a-sha',
            ]);

            self::assertSame(Command::INVALID, $exitCode);
            self::assertStringContainsString('Option --sha256 must be a 64-character hexadecimal SHA-256', $tester->getDisplay());
        } finally {
            $filesystem->remove($workspace);
        }
    }
}
