<?php

namespace App\Application\AppPolicy;

final class GetAppFeaturesEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_FORBIDDEN_ACTOR = 'FORBIDDEN_ACTOR';
    public const STATUS_SUCCESS = 'SUCCESS';

    public function __construct(
        private string $status,
        private ?GetAppFeaturesResult $features = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function features(): ?GetAppFeaturesResult
    {
        return $this->features;
    }
}
