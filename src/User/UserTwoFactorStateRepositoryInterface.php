<?php

namespace App\User;

interface UserTwoFactorStateRepositoryInterface
{
    public function findByUserId(string $userId): ?UserTwoFactorState;

    public function save(UserTwoFactorState $state): void;

    public function delete(string $userId): void;
}
