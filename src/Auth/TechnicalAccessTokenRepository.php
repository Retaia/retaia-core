<?php

namespace App\Auth;

use Doctrine\ORM\EntityManagerInterface;

final class TechnicalAccessTokenRepository implements TechnicalAccessTokenRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByClientId(string $clientId): ?TechnicalAccessTokenRecord
    {
        $record = $this->entityManager->find(TechnicalAccessTokenRecord::class, $clientId);
        if ($record instanceof TechnicalAccessTokenRecord) {
            $this->entityManager->refresh($record);
        }

        return $record instanceof TechnicalAccessTokenRecord ? $record : null;
    }

    public function findByAccessToken(string $accessToken): ?TechnicalAccessTokenRecord
    {
        $record = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(TechnicalAccessTokenRecord::class, 't')
            ->andWhere('t.accessToken = :accessToken')
            ->setParameter('accessToken', $accessToken)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($record instanceof TechnicalAccessTokenRecord) {
            $this->entityManager->refresh($record);
        }

        return $record instanceof TechnicalAccessTokenRecord ? $record : null;
    }

    public function save(TechnicalAccessTokenRecord $record): void
    {
        $existing = $this->entityManager->find(TechnicalAccessTokenRecord::class, $record->clientId);
        if ($existing instanceof TechnicalAccessTokenRecord) {
            $existing->syncFrom($record);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function deleteByClientId(string $clientId): void
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return;
        }

        $record = $this->entityManager->find(TechnicalAccessTokenRecord::class, $clientId);
        if (!$record instanceof TechnicalAccessTokenRecord) {
            return;
        }

        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }
}
