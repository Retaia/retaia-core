<?php

namespace App\Asset\Service;

use RuntimeException;

final class StateConflictException extends RuntimeException
{
    public function __construct(
        string $message,
    ) {
        parent::__construct($message);
    }
}
