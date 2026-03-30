<?php

namespace App\Tests\Unit\User\Repository;

use App\Entity\User;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    public function testFindByEmailDelegatesToEntityManager(): void
    {
        $user = new User('user-1', 'john@example.com', 'hash', ['ROLE_USER']);
        $doctrineRepository = $this->createMock(EntityRepository::class);
        $doctrineRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['email' => 'john@example.com'])
            ->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($doctrineRepository);

        $repository = new UserRepository($entityManager);

        self::assertSame($user, $repository->findByEmail('john@example.com'));
    }

    public function testFindByIdDelegatesToEntityManager(): void
    {
        $user = new User('user-1', 'john@example.com', 'hash', ['ROLE_USER']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('find')
            ->with(User::class, 'user-1')
            ->willReturn($user);

        $repository = new UserRepository($entityManager);

        self::assertSame($user, $repository->findById('user-1'));
    }

    public function testSaveDelegatesToEntityManager(): void
    {
        $user = new User('user-1', 'john@example.com', 'hash', ['ROLE_USER']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with($user);
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $repository = new UserRepository($entityManager);

        $repository->save($user);
    }
}
