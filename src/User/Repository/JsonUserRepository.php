<?php

namespace App\User\Repository;

use App\User\Model\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class JsonUserRepository implements UserRepositoryInterface
{
    public function __construct(
        #[Autowire('%app.user_storage_path%')]
        private string $storagePath,
    ) {
        $this->bootstrapStorage();
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->readStorage() as $entry) {
            if (strtolower((string) ($entry['email'] ?? '')) !== strtolower($email)) {
                continue;
            }

            return $this->hydrate($entry);
        }

        return null;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->readStorage() as $entry) {
            if (($entry['id'] ?? null) !== $id) {
                continue;
            }

            return $this->hydrate($entry);
        }

        return null;
    }

    public function save(User $user): void
    {
        $data = $this->readStorage();
        $saved = false;

        foreach ($data as $index => $entry) {
            if (($entry['id'] ?? null) !== $user->getId()) {
                continue;
            }

            $data[$index] = $this->normalize($user);
            $saved = true;
        }

        if (!$saved) {
            $data[] = $this->normalize($user);
        }

        $this->writeStorage($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readStorage(): array
    {
        if (!is_file($this->storagePath)) {
            return [];
        }

        $content = file_get_contents($this->storagePath);
        if ($content === false || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeStorage(array $rows): void
    {
        file_put_contents($this->storagePath, (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function hydrate(array $entry): User
    {
        $roles = $entry['roles'] ?? ['ROLE_USER'];
        if (!is_array($roles)) {
            $roles = ['ROLE_USER'];
        }

        return new User(
            (string) ($entry['id'] ?? ''),
            (string) ($entry['email'] ?? ''),
            (string) ($entry['password_hash'] ?? ''),
            array_map(static fn ($role): string => (string) $role, $roles),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'password_hash' => $user->getPassword(),
            'roles' => $user->getRoles(),
        ];
    }

    private function bootstrapStorage(): void
    {
        $directory = dirname($this->storagePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (is_file($this->storagePath)) {
            return;
        }

        $defaultUser = new User(
            bin2hex(random_bytes(8)),
            'admin@retaia.local',
            password_hash('change-me', PASSWORD_DEFAULT),
            ['ROLE_ADMIN'],
        );
        $this->writeStorage([$this->normalize($defaultUser)]);
    }
}

