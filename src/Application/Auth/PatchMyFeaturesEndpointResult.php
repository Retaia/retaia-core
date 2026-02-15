<?php

namespace App\Application\Auth;

final class PatchMyFeaturesEndpointResult
{
    public const STATUS_UNAUTHORIZED = 'UNAUTHORIZED';
    public const STATUS_VALIDATION_FAILED_PAYLOAD = 'VALIDATION_FAILED_PAYLOAD';
    public const STATUS_FORBIDDEN_SCOPE = 'FORBIDDEN_SCOPE';
    public const STATUS_VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const STATUS_UPDATED = 'UPDATED';

    /**
     * @param array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}|null $validationDetails
     */
    public function __construct(
        private string $status,
        private ?array $validationDetails = null,
        private ?MyFeaturesResult $features = null,
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

    public function features(): ?MyFeaturesResult
    {
        return $this->features;
    }
}
