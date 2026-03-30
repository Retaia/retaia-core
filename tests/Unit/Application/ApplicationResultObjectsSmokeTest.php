<?php

namespace App\Tests\Unit\Application;

use App\Application\AppPolicy\GetAppFeaturesResult;
use App\Application\Auth\MyFeaturesResult;
use App\Job\Job;
use App\Job\JobStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationResultObjectsSmokeTest extends TestCase
{
    #[DataProvider('resultClasses')]
    public function testResultClassCanBeInstantiatedAndQueried(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        $instance = $reflection->newInstanceArgs(array_map(
            fn (\ReflectionParameter $parameter): mixed => $this->argumentFor($parameter),
            $reflection->getConstructor()?->getParameters() ?? [],
        ));

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0 || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $method->invoke($instance);
        }

        self::assertInstanceOf($className, $instance);
    }

    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function resultClasses(): iterable
    {
        $root = dirname(__DIR__, 3).'/src/Application';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            if (!str_ends_with($pathname, 'Result.php') && !str_ends_with($pathname, 'EndpointResult.php')) {
                continue;
            }

            $className = 'App\\Application\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                substr($pathname, strlen($root) + 1),
            );

            yield $className => [$className];
        }
    }

    private function argumentFor(\ReflectionParameter $parameter): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $type = $parameter->getType();
        if (!$type instanceof \ReflectionNamedType) {
            return null;
        }

        if ($type->allowsNull()) {
            return null;
        }

        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'string' => strtoupper($parameter->getName()),
                'int' => 1,
                'float' => 1.0,
                'bool' => true,
                'array' => [],
                default => null,
            };
        }

        return match ($type->getName()) {
            MyFeaturesResult::class => new MyFeaturesResult([], [], [], [], []),
            GetAppFeaturesResult::class => new GetAppFeaturesResult([], [], [], []),
            Job::class => new Job('job-1', 'asset-1', 'extract_facts', JobStatus::PENDING, null, null, null),
            default => throw new \RuntimeException(sprintf('Unhandled result parameter type: %s', $type->getName())),
        };
    }
}
