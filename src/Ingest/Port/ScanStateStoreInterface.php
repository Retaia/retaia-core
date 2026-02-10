<?php

namespace App\Ingest\Port;

interface ScanStateStoreInterface
{
    /**
     * @return array{path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string,first_seen_at:\DateTimeImmutable,last_seen_at:\DateTimeImmutable}
     */
    public function recordDetectedFile(string $path, int $size, \DateTimeImmutable $mtime, \DateTimeImmutable $scannedAt): array;

    /**
     * @return list<array{path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string}>
     */
    public function listStableFiles(int $limit = 100): array;

    public function markQueued(string $path, \DateTimeImmutable $queuedAt): void;

    public function markMissing(string $path, \DateTimeImmutable $at): void;
}
