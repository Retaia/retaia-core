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

        self::assertCount(9, $users);

        $admin = $repository->findOneBy(['email' => 'admin@retaia.local']);
        self::assertInstanceOf(User::class, $admin);
        self::assertTrue(password_verify('change-me', $admin->getPassword()));
        self::assertTrue($admin->isEmailVerified());

        $unverified = $repository->findOneBy(['email' => 'pending@retaia.local']);
        self::assertInstanceOf(User::class, $unverified);
        self::assertFalse($unverified->isEmailVerified());

        $agent = $repository->findOneBy(['email' => 'agent@retaia.local']);
        self::assertInstanceOf(User::class, $agent);
        self::assertContains('ROLE_AGENT', $agent->getRoles());

        $operator = $repository->findOneBy(['email' => 'operator@retaia.local']);
        self::assertInstanceOf(User::class, $operator);
        self::assertSame(['ROLE_USER'], $operator->getRoles());
    }
}
