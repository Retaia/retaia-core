<?php

namespace App\Tests\Unit\Controller;

trait ControllerInstantiationTrait
{
    /**
     * @param class-string $className
     * @param array<string, mixed> $properties
     */
    private function controller(string $className, array $properties): object
    {
        $reflection = new \ReflectionClass($className);
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($properties as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($instance, $value);
        }

        return $instance;
    }
}
