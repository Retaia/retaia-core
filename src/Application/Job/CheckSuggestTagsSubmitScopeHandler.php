<?php

namespace App\Application\Job;

final class CheckSuggestTagsSubmitScopeHandler
{
    public function __construct(
        private bool $featureSuggestTagsEnabled,
    ) {
    }

    /**
     * @param array<int, string> $roles
     */
    public function handle(array $roles): bool
    {
        return $this->featureSuggestTagsEnabled && in_array('ROLE_SUGGESTIONS_WRITE', $roles, true);
    }
}
