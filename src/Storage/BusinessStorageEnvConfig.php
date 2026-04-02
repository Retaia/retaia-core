<?php

namespace App\Storage;

final readonly class BusinessStorageEnvConfig
{
    /**
     * @param list<string>|null $managedDirectories
     */
    public function __construct(
        public string $id,
        public string $driver,
        public string $watchDirectory,
        public ?array $managedDirectories,
        public bool $ingestEnabled,
        public ?string $rootPath = null,
        public ?string $host = null,
        public ?string $share = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $workgroup = null,
        public ?string $rootPrefix = null,
        public ?string $minProtocol = null,
        public ?string $maxProtocol = null,
        public int $timeoutSeconds = 20,
    ) {
    }
}
