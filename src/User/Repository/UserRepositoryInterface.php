<?php

namespace App\User\Repository;

use App\User\Model\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function findById(string $id): ?User;

    public function save(User $user): void;
}

