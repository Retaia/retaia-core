<?php

namespace App\Feature;

final class FeaturePayloadValidator
{
    /**
     * @param array<string, mixed> $features
     * @param array<int, string>   $allowedKeys
     *
     * @return array{unknown_keys: array<int, string>, non_boolean_keys: array<int, string>}
     */
    public function validateFeaturePayload(array $features, array $allowedKeys): array
    {
        $allowedLookup = array_fill_keys($allowedKeys, true);
        $unknownKeys = [];
        $nonBooleanKeys = [];

        foreach ($features as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (!array_key_exists($key, $allowedLookup)) {
                $unknownKeys[] = $key;
                continue;
            }
            if (!is_bool($value)) {
                $nonBooleanKeys[] = $key;
            }
        }

        sort($unknownKeys);
        sort($nonBooleanKeys);

        return [
            'unknown_keys' => $unknownKeys,
            'non_boolean_keys' => $nonBooleanKeys,
        ];
    }
}
