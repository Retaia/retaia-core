<?php

namespace App\Derived;

use Doctrine\ORM\EntityManagerInterface;

final class DerivedFileRepository implements DerivedFileRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedFile
    {
        $id = bin2hex(random_bytes(8));
        $createdAt = new \DateTimeImmutable();
        $storagePath = sprintf('/derived/%s/%s', $assetUuid, $id);
        $file = new DerivedFile($id, $assetUuid, $kind, $contentType, $sizeBytes, $sha256, $storagePath, $createdAt);

        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return $file;
    }

    public function listByAsset(string $assetUuid): array
    {
        /** @var list<DerivedFile> $files */
        $files = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DerivedFile::class, 'd')
            ->andWhere('d.assetUuid = :assetUuid')
            ->setParameter('assetUuid', $assetUuid)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($files as $file) {
            $this->entityManager->refresh($file);
        }

        return $files;
    }

    public function findLatestByAssetAndKind(string $assetUuid, string $kind): ?DerivedFile
    {
        $file = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DerivedFile::class, 'd')
            ->andWhere('d.assetUuid = :assetUuid')
            ->andWhere('d.kind = :kind')
            ->setParameter('assetUuid', $assetUuid)
            ->setParameter('kind', $kind)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($file instanceof DerivedFile) {
            $this->entityManager->refresh($file);
        }

        return $file instanceof DerivedFile ? $file : null;
    }

    public function upsertMaterialized(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256, string $storagePath): void
    {
        $existing = $this->findLatestByAssetAndKind($assetUuid, $kind);
        if ($existing !== null) {
            $existing->syncMaterialized($contentType, $sizeBytes, $sha256, $storagePath);
            $this->entityManager->flush();

            return;
        }

        $this->entityManager->persist(new DerivedFile(
            bin2hex(random_bytes(8)),
            $assetUuid,
            $kind,
            $contentType,
            $sizeBytes,
            $sha256,
            $storagePath,
            new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }

    public function listStoragePathsByAsset(string $assetUuid): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.storagePath')
            ->from(DerivedFile::class, 'd')
            ->andWhere('d.assetUuid = :assetUuid')
            ->setParameter('assetUuid', $assetUuid)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['storagePath'] ?? ($row['storage_path'] ?? null))
                ? (string) ($row['storagePath'] ?? $row['storage_path'])
                : null,
            $rows
        )));
    }

    public function deleteByAsset(string $assetUuid): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(DerivedFile::class, 'd')
            ->andWhere('d.assetUuid = :assetUuid')
            ->setParameter('assetUuid', $assetUuid)
            ->getQuery()
            ->execute();
    }
}
