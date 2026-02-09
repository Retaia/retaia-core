<?php

namespace App\Tests\Functional;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\User\Repository\PasswordResetTokenRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PasswordResetCleanupCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    public function testCleanupCommandPurgesOnlyExpiredTokens(): void
    {
        static::bootKernel();
        $container = static::getContainer();

        /** @var PasswordResetTokenRepositoryInterface $tokens */
        $tokens = $container->get(PasswordResetTokenRepositoryInterface::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $admin = $entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@retaia.local']);
        self::assertInstanceOf(User::class, $admin);

        $tokens->save($admin->getId(), hash('sha256', 'expired-token'), new \DateTimeImmutable('-5 minutes'));
        $tokens->save($admin->getId(), hash('sha256', 'valid-token'), new \DateTimeImmutable('+30 minutes'));

        $before = $entityManager->getRepository(PasswordResetToken::class)->count([]);
        self::assertSame(2, $before);

        $application = new Application(static::$kernel);
        $command = $application->find('app:password-reset:cleanup');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('Purged 1 expired password reset token', $tester->getDisplay());

        $after = $entityManager->getRepository(PasswordResetToken::class)->count([]);
        self::assertSame(1, $after);
    }
}
