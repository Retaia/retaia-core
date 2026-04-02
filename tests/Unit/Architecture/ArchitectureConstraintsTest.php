<?php

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureConstraintsTest extends TestCase
{
    public function testControllersDoNotImportDbalOrExecuteSqlDirectly(): void
    {
        $violations = $this->findViolations(
            $this->phpFilesIn('/src/Controller'),
            [
                'Doctrine\\DBAL\\Connection',
                '->executeQuery(',
                '->executeStatement(',
                '->fetchAllAssociative(',
                '->fetchAssociative(',
                '->fetchOne(',
                '->prepare(',
            ],
            static fn (string $path): bool => str_ends_with($path, 'Controller.php')
        );

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testApplicationLayerDoesNotImportDbalOrExecuteSqlDirectly(): void
    {
        $violations = $this->findViolations(
            $this->phpFilesIn('/src/Application'),
            [
                'Doctrine\\DBAL\\Connection',
                '->executeQuery(',
                '->executeStatement(',
                '->fetchAllAssociative(',
                '->fetchAssociative(',
                '->fetchOne(',
                '->prepare(',
            ]
        );

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testLeagueFlysystemStaysInsideStorageNamespace(): void
    {
        $violations = $this->findViolations(
            $this->phpFilesIn('/src'),
            [
                'League\\Flysystem',
            ],
            static fn (string $path): bool => !str_contains($path, '/src/Storage/')
        );

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * @param list<string> $patterns
     * @param null|callable(string): bool $filter
     * @return list<string>
     */
    private function findViolations(array $files, array $patterns, ?callable $filter = null): array
    {
        $violations = [];

        foreach ($files as $file) {
            if ($filter !== null && !$filter($file)) {
                continue;
            }

            $contents = file_get_contents($file); // @unit-purity-ignore
            if (!is_string($contents)) {
                $violations[] = $this->relativePath($file).': unreadable';

                continue;
            }

            foreach ($patterns as $pattern) {
                if (!str_contains($contents, $pattern)) {
                    continue;
                }

                $violations[] = sprintf('%s contains forbidden pattern `%s`', $this->relativePath($file), $pattern);
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $relativeDir): array
    {
        $baseDir = $this->repoRoot().$relativeDir;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        $paths = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function relativePath(string $path): string
    {
        return str_replace($this->repoRoot().'/', '', $path);
    }
}
