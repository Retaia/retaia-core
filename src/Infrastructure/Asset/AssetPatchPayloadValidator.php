<?php

namespace App\Infrastructure\Asset;

final class AssetPatchPayloadValidator
{
    private const PROCESSING_PROFILES = ['video_standard', 'audio_undefined', 'audio_music', 'audio_voice', 'photo_standard'];

    public function isValidTagsPayload(mixed $tags): bool
    {
        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                return false;
            }
        }

        return true;
    }

    public function isValidNotesPayload(mixed $notes): bool
    {
        return $notes === null || is_string($notes);
    }

    public function isValidFieldsPayload(mixed $fields): bool
    {
        return is_array($fields);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $payload
     */
    public function applyMutableMetadataFields(array &$fields, array $payload): bool
    {
        $dateTimeFields = ['captured_at'];
        foreach ($dateTimeFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && (!$this->isNonEmptyString($value) || !$this->isValidDateTime($value))) {
                return false;
            }
            $fields[$field] = $value;
        }

        foreach ([
            'gps_latitude' => [-90, 90],
            'gps_longitude' => [-180, 180],
            'gps_altitude_m' => null,
            'gps_altitude_relative_m' => null,
            'gps_altitude_absolute_m' => null,
        ] as $field => $range) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && !is_int($value) && !is_float($value)) {
                return false;
            }
            if (is_array($range) && $value !== null && ($value < $range[0] || $value > $range[1])) {
                return false;
            }
            $fields[$field] = $value;
        }

        foreach (['location_country', 'location_city', 'location_label'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            $value = $payload[$field];
            if ($value !== null && !is_string($value)) {
                return false;
            }
            $fields[$field] = $value;
        }

        if (array_key_exists('processing_profile', $payload)) {
            $value = $payload['processing_profile'];
            if ($value !== null && (!is_string($value) || !in_array($value, self::PROCESSING_PROFILES, true))) {
                return false;
            }
            $fields['processing_profile'] = $value;
        }

        return true;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isValidDateTime(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
