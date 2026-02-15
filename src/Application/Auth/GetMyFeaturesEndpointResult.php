<?php

namespace App\Application\Auth;

final class GetMyFeaturesEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private string $status,
        private ?MyFeaturesResult $features = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function features(): ?MyFeaturesResult
    {
        return $this->features;
    }
}
