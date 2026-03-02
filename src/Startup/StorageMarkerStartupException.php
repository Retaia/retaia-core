<?php

namespace App\Startup;

final class StorageMarkerStartupException extends \RuntimeException
{
    public function __construct(
        private string $startupCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('[%s] %s', $startupCode, $message), 0, $previous);
    }

    public function startupCode(): string
    {
        return $this->startupCode;
    }
}

