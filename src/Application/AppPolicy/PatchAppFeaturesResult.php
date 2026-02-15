<?php

namespace App\Application\AppPolicy;

final class PatchAppFeaturesResult
{
    public const STATUS_UPDATED = 'UPDATED';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';

    /**
     * @param array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}|null $validationDetails
     */
    public function __construct(
        private string $status,
        private ?array $validationDetails = null,
        private ?GetAppFeaturesResult $features = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}|null
     */
    public function validationDetails(): ?array
    {
        return $this->validationDetails;
    }

    public function features(): ?GetAppFeaturesResult
    {
        return $this->features;
    }
}
