<?php

namespace App\Tests\Unit\User\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\User\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenRepositoryTest extends TestCase
{
    public function testSaveConsumeAndPurgeExpiredUseEntityManager(): void
    {
        $user = new User('user-1', 'john@example.com', 'hash', ['ROLE_USER']);
        $token = new PasswordResetToken($user, 'token-hash', new \DateTimeImmutable('+1 hour'));

        $doctrineRepository = $this->createMock(EntityRepository::class);
        $doctrineRepository->method('findOneBy')->with(['tokenHash' => 'token-hash'])->willReturn($token);

        $query = $this->getMockBuilder(Query::class)->disableOriginalConstructor()->onlyMethods(['setParameter', 'execute'])->getMock();
        $query->expects(self::exactly(2))->method('setParameter')->with('now', self::isInstanceOf(\DateTimeImmutable::class))->willReturnSelf();
        $query->expects(self::exactly(2))->method('execute')->willReturnOnConsecutiveCalls(1, 2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getReference')->with(User::class, 'user-1')->willReturn($user);
        $entityManager->method('getRepository')->with(PasswordResetToken::class)->willReturn($doctrineRepository);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('remove')->with($token);
        $entityManager->expects(self::exactly(2))->method('flush');
        $entityManager->expects(self::exactly(2))->method('createQuery')->willReturn($query);

        $repository = new PasswordResetTokenRepository($entityManager);

        $repository->save('user-1', 'token-hash', new \DateTimeImmutable('+1 hour'));
        self::assertSame('user-1', $repository->consumeValid('token-hash', new \DateTimeImmutable()));
        self::assertSame(2, $repository->purgeExpired(new \DateTimeImmutable()));
    }
}
