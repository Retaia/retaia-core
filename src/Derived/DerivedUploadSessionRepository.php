<?php

namespace App\Derived;

use Doctrine\ORM\EntityManagerInterface;

final class DerivedUploadSessionRepository implements DerivedUploadSessionRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(string $assetUuid, string $kind, string $contentType, int $sizeBytes, ?string $sha256): DerivedUploadSession
    {
        $uploadId = bin2hex(random_bytes(12));
        $now = new \DateTimeImmutable();
        $session = new DerivedUploadSession($uploadId, $assetUuid, $kind, $contentType, $sizeBytes, $sha256, 'open', 0, $now, $now);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    public function find(string $uploadId): ?DerivedUploadSession
    {
        $session = $this->entityManager->getRepository(DerivedUploadSession::class)->find($uploadId);
        if (!$session instanceof DerivedUploadSession) {
            return null;
        }

        $this->entityManager->refresh($session);

        return $session;
    }

    public function updateHighestPartCount(string $uploadId, int $partNumber): void
    {
        $session = $this->find($uploadId);
        if (!$session instanceof DerivedUploadSession) {
            return;
        }

        $session->updateHighestPartCount($partNumber, new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function markCompleted(string $uploadId): void
    {
        $session = $this->find($uploadId);
        if (!$session instanceof DerivedUploadSession) {
            return;
        }

        $session->markCompleted(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
