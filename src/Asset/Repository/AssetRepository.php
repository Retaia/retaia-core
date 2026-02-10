<?php

namespace App\Asset\Repository;

use App\Asset\AssetState;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;

final class AssetRepository implements AssetRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUuid(string $uuid): ?Asset
    {
        $asset = $this->entityManager->find(Asset::class, $uuid);

        return $asset instanceof Asset ? $asset : null;
    }

    public function listAssets(?string $state, ?string $mediaType, ?string $query, int $limit): array
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Asset::class, 'a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)));

        if ($state !== null && $state !== '') {
            $assetState = AssetState::tryFrom($state);
            if ($assetState !== null) {
                $builder->andWhere('a.state = :state')->setParameter('state', $assetState);
            }
        }

        if ($mediaType !== null && $mediaType !== '') {
            $builder->andWhere('a.mediaType = :mediaType')->setParameter('mediaType', strtoupper($mediaType));
        }

        if ($query !== null && $query !== '') {
            $builder
                ->andWhere('LOWER(a.filename) LIKE :q OR LOWER(a.notes) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%');
        }

        /** @var array<int, Asset> $assets */
        $assets = $builder->getQuery()->getResult();

        return $assets;
    }

    public function save(Asset $asset): void
    {
        $this->entityManager->persist($asset);
        $this->entityManager->flush();
    }
}
