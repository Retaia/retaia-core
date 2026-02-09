<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserFixturesTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testAliceFixturesLoadUsersWithFakerData(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $repository = $entityManager->getRepository(User::class);
        $users = $repository->findAll();

        self::assertCount(6, $users);

        $admin = $repository->findOneBy(['email' => 'admin@retaia.local']);
        self::assertInstanceOf(User::class, $admin);
        self::assertTrue(password_verify('change-me', $admin->getPassword()));
    }
}
