<?php

namespace App\Tests\Support;

use App\Entity\User;
use App\User\Repository\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /**
     * @var array<string, User>
     */
    private array $usersById = [];

    public function seedDefaultAdmin(): void
    {
        $this->save(new User(
            'testadmin00000001',
            'admin@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_ADMIN'],
            true,
        ));
    }

    public function seedUnverifiedUser(string $email, string $plainPassword): void
    {
        $this->save(new User(
            substr(hash('sha256', $email), 0, 32),
            $email,
            password_hash($plainPassword, PASSWORD_DEFAULT),
            ['ROLE_USER'],
            false,
        ));
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->usersById as $user) {
            if (strtolower($user->getEmail()) === strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    public function findById(string $id): ?User
    {
        return $this->usersById[$id] ?? null;
    }

    public function save(User $user): void
    {
        $this->usersById[$user->getId()] = $user;
    }
}
