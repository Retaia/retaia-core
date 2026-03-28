<?php

namespace App\Storage;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\Options;
use Icewind\SMB\ServerFactory;
use Jerodev\Flysystem\Smb\SmbAdapter;
use League\Flysystem\Filesystem;

final class SmbBusinessStorageFactory
{
    public function __construct(
        private BusinessStorageConfig $config,
        private string $host,
        private string $share,
        private string $username,
        private string $password,
        private ?string $workgroup = null,
        private string $rootPrefix = '',
        private ?string $minProtocol = null,
        private ?string $maxProtocol = null,
        private int $timeoutSeconds = 20,
    ) {
    }

    public function create(): BusinessStorageInterface
    {
        $options = new Options();
        $options->setTimeout($this->timeoutSeconds);
        $options->setMinProtocol($this->minProtocol);
        $options->setMaxProtocol($this->maxProtocol);

        $server = (new ServerFactory($options))->createServer(
            $this->host,
            new BasicAuth($this->username, $this->workgroup, $this->password)
        );

        return new FlysystemBusinessStorage(
            new Filesystem(new SmbAdapter($server->getShare($this->share), $this->normalizeRootPrefix($this->rootPrefix))),
            $this->config,
        );
    }

    private function normalizeRootPrefix(string $prefix): string
    {
        return trim(str_replace('\\', '/', $prefix), '/');
    }
}
