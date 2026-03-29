<?php

namespace App\Auth;

use Doctrine\ORM\EntityManagerInterface;

final class AuthDeviceFlowRepository implements AuthDeviceFlowRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByDeviceCode(string $deviceCode): ?AuthDeviceFlow
    {
        $flow = $this->entityManager->find(AuthDeviceFlow::class, $deviceCode);
        if ($flow instanceof AuthDeviceFlow) {
            $this->entityManager->refresh($flow);
        }

        return $flow instanceof AuthDeviceFlow ? $flow : null;
    }

    public function findByUserCode(string $userCode): ?AuthDeviceFlow
    {
        $flow = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(AuthDeviceFlow::class, 'f')
            ->andWhere('f.userCode = :userCode')
            ->setParameter('userCode', strtoupper(trim($userCode)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($flow instanceof AuthDeviceFlow) {
            $this->entityManager->refresh($flow);
        }

        return $flow instanceof AuthDeviceFlow ? $flow : null;
    }

    public function save(AuthDeviceFlow $flow): void
    {
        $existing = $this->entityManager->find(AuthDeviceFlow::class, $flow->deviceCode);
        if ($existing instanceof AuthDeviceFlow) {
            $existing->syncFrom($flow);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist($flow);
        $this->entityManager->flush();
    }

    public function delete(string $deviceCode): void
    {
        $deviceCode = trim($deviceCode);
        if ($deviceCode === '') {
            return;
        }

        $flow = $this->entityManager->find(AuthDeviceFlow::class, $deviceCode);
        if (!$flow instanceof AuthDeviceFlow) {
            return;
        }

        $this->entityManager->remove($flow);
        $this->entityManager->flush();
    }
}
