<?php

namespace App\Feature;

final class FeatureExplanationBuilder
{
    /**
     * @param array<string, bool> $appEnabled
     * @param array<string, bool> $featureFlags
     * @param array<string, array<string, mixed>> $rules
     * @param array<int, string> $allowedAppFeatureKeys
     * @param array<int, string> $coreV1GlobalFeatures
     * @return array<string, array<string, mixed>>
     */
    public function appFeatureExplanations(
        array $appEnabled,
        array $featureFlags,
        array $rules,
        array $allowedAppFeatureKeys,
        array $coreV1GlobalFeatures,
    ): array {
        $explanations = [];

        foreach ($coreV1GlobalFeatures as $coreFeature) {
            $explanations[$coreFeature] = [
                'effective_value' => true,
                'reason_code' => 'CORE_PROTECTED',
            ];
        }

        foreach ($allowedAppFeatureKeys as $key) {
            $effective = (bool) ($appEnabled[$key] ?? true);
            $explanation = ['effective_value' => $effective];

            if ($key === 'features.ai') {
                if ($effective === false) {
                    $explanation['reason_code'] = 'ADMIN_DISABLED';
                }
                $explanations[$key] = $explanation;
                continue;
            }

            if (($featureFlags[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'FEATURE_FLAG_OFF';
                $explanations[$key] = $explanation;
                continue;
            }

            if (str_starts_with($key, 'features.ai.') && ($appEnabled['features.ai'] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanation['parent_feature_key'] = 'features.ai';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($appEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanations[$key] = $explanation;
                continue;
            }

            foreach ((array) ($rules[$key]['dependencies'] ?? []) as $dependency) {
                $dependencyKey = (string) $dependency;
                if (($appEnabled[$dependencyKey] ?? false) === false || ($featureFlags[$dependencyKey] ?? true) === false) {
                    $explanation['effective_value'] = false;
                    $explanation['reason_code'] = 'DEPENDENCY_OFF';
                    $explanation['dependency_key'] = $dependencyKey;
                    break;
                }
            }

            $explanations[$key] = $explanation;
        }

        return $this->disableEscalatedChildren($rules, $explanations);
    }

    /**
     * @param array<string, bool> $userEnabled
     * @param array<string, bool> $appEnabled
     * @param array<string, bool> $featureFlags
     * @param array<string, array<string, mixed>> $rules
     * @param array<int, string> $allowedUserFeatureKeys
     * @param array<int, string> $coreV1GlobalFeatures
     * @return array<string, array<string, mixed>>
     */
    public function userFeatureExplanations(
        array $userEnabled,
        array $appEnabled,
        array $featureFlags,
        array $rules,
        array $allowedUserFeatureKeys,
        array $coreV1GlobalFeatures,
    ): array {
        $explanations = [];
        foreach ($coreV1GlobalFeatures as $coreFeature) {
            $explanations[$coreFeature] = [
                'effective_value' => true,
                'reason_code' => 'CORE_PROTECTED',
            ];
        }

        foreach ($allowedUserFeatureKeys as $key) {
            $explanation = ['effective_value' => true];

            if (($featureFlags[$key] ?? false) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'FEATURE_FLAG_OFF';
                $explanations[$key] = $explanation;
                continue;
            }

            if (str_starts_with($key, 'features.ai.') && ($appEnabled['features.ai'] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanation['parent_feature_key'] = 'features.ai';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($appEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'ADMIN_DISABLED';
                $explanations[$key] = $explanation;
                continue;
            }

            if (($userEnabled[$key] ?? true) === false) {
                $explanation['effective_value'] = false;
                $explanation['reason_code'] = 'USER_OPT_OUT';
                $explanations[$key] = $explanation;
                continue;
            }

            foreach ((array) ($rules[$key]['dependencies'] ?? []) as $dependency) {
                $dependencyKey = (string) $dependency;
                if ((bool) ($explanations[$dependencyKey]['effective_value'] ?? false) === false) {
                    $explanation['effective_value'] = false;
                    $explanation['reason_code'] = 'DEPENDENCY_OFF';
                    $explanation['dependency_key'] = $dependencyKey;
                    break;
                }
            }

            $explanations[$key] = $explanation;
        }

        return $this->disableEscalatedChildren($rules, $explanations);
    }

    /**
     * @param array<string, array<string, mixed>> $rules
     * @param array<string, array<string, mixed>> $explanations
     * @return array<string, array<string, mixed>>
     */
    private function disableEscalatedChildren(array $rules, array $explanations): array
    {
        foreach ($rules as $key => $rule) {
            if ((bool) ($explanations[$key]['effective_value'] ?? false) !== false) {
                continue;
            }

            foreach ((array) ($rule['disable_escalation'] ?? []) as $child) {
                $childKey = (string) $child;
                $explanations[$childKey] = [
                    'effective_value' => false,
                    'reason_code' => 'DISABLE_ESCALATION',
                    'parent_feature_key' => $key,
                ];
            }
        }

        return $explanations;
    }
}
