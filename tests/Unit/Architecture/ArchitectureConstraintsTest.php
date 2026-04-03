<?php

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureConstraintsTest extends TestCase
{
    private const INLINE_CODE_MESSAGE_JSON_RESPONSE_PATTERN =
        "/new\\s+JsonResponse\\s*\\(\\s*\\[.*?['\\\"]code['\\\"]\\s*=>.*?['\\\"]message['\\\"]\\s*=>/s";

    private const JSON_RESPONSE_WITH_CODE_PATTERN =
        "/new\\s+JsonResponse\\s*\\(.*?['\\\"]code['\\\"]\\s*=>/s";

    public function testControllersDoNotImportDbalOrExecuteSqlDirectly(): void
    {
        $violations = $this->findViolations(
            $this->phpFilesIn('/src/Controller'),
            [
                'Doctrine\\DBAL\\Connection',
                '->executeQuery(',
                '->executeStatement(',
                '->executeUpdate(',
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
                '->executeUpdate(',
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

    public function testInlineCodeMessageJsonErrorEnvelopesDoNotReturnInSrc(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn('/src') as $file) {
            $contents = file_get_contents($file); // @unit-purity-ignore
            if (!is_string($contents)) {
                $violations[] = $this->relativePath($file).': unreadable';

                continue;
            }

            if (!str_contains($contents, 'new JsonResponse(')) {
                continue;
            }

            $matchResult = preg_match(self::INLINE_CODE_MESSAGE_JSON_RESPONSE_PATTERN, $contents);
            if ($matchResult === false) {
                self::fail(sprintf(
                    'Regex error while checking inline code/message JsonResponse envelope in %s',
                    $this->relativePath($file)
                ));
            }

            if ($matchResult === 1) {
                $violations[] = sprintf('%s rebuilds an inline code/message JsonResponse envelope', $this->relativePath($file));
            }
        }

        sort($violations);

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testErrorJsonResponsesUseCentralizedFactoryOrResponderTrait(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn('/src') as $file) {
            $contents = file_get_contents($file); // @unit-purity-ignore
            if (!is_string($contents)) {
                $violations[] = $this->relativePath($file).': unreadable';

                continue;
            }

            $matchResult = preg_match(self::JSON_RESPONSE_WITH_CODE_PATTERN, $contents);
            if ($matchResult === false) {
                $violations[] = $this->relativePath($file).': regex error while searching for JsonResponse error payloads';

                continue;
            }

            if ($matchResult !== 1) {
                continue;
            }

            $analysis = $this->analyzeErrorResponderUsage($contents);
            $usesFactory = $analysis['usesFactory'] || $analysis['usesErrorResponseCall'];
            $usesTrait = $analysis['usesTrait'];

            if (!$usesFactory && !$usesTrait) {
                $violations[] = sprintf(
                    '%s constructs JsonResponse error payloads without using the centralized error responders',
                    $this->relativePath($file)
                );
            }
        }

        sort($violations);

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * @return array{usesFactory: bool, usesTrait: bool, usesErrorResponseCall: bool}
     */
    private function analyzeErrorResponderUsage(string $contents): array
    {
        $tokens = token_get_all($contents);

        $usesFactory = false;
        $usesTrait = false;
        $usesErrorResponseCall = false;

        $tokenCount = \count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            $tokenId = $token[0];
            $tokenText = $token[1];

            if ($tokenId !== T_STRING && (!defined('T_NAME_QUALIFIED') || $tokenId !== T_NAME_QUALIFIED)) {
                continue;
            }

            if ($tokenText === 'ApiErrorResponseFactory') {
                $usesFactory = true;

                continue;
            }

            if ($tokenText === 'ApiErrorResponderTrait') {
                $usesTrait = true;

                continue;
            }

            if ($tokenText !== 'errorResponse') {
                continue;
            }

            $j = $i + 1;
            while ($j < $tokenCount) {
                $next = $tokens[$j];
                if (\is_array($next)) {
                    if (\in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $j++;

                        continue;
                    }

                    break;
                }

                if ($next === '(') {
                    $usesErrorResponseCall = true;
                }

                break;
            }
        }

        return [
            'usesFactory' => $usesFactory,
            'usesTrait' => $usesTrait,
            'usesErrorResponseCall' => $usesErrorResponseCall,
        ];
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
