<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> */
    public array $usersByEmail = [];

    public function __construct(?User $initialUser = null)
    {
        if ($initialUser instanceof User) {
            $this->save($initialUser);
        }
    }

    public function findByEmail(string $email): ?User
    {
        return $this->usersByEmail[strtolower($email)] ?? null;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->usersByEmail as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function save(User $user): void
    {
        $this->usersByEmail[strtolower($user->getEmail())] = $user;
    }

    public function seedDefaultAdmin(): User
    {
        $user = new User(
            'bootstrapadmin0001',
            'admin@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_ADMIN'],
            true,
        );

        $this->save($user);

        return $user;
    }

    public function seedUnverifiedUser(string $email, string $password): User
    {
        $user = new User(
            'user'.substr(hash('sha256', strtolower($email)), 0, 28),
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        );

        $this->save($user);

        return $user;
    }
}
