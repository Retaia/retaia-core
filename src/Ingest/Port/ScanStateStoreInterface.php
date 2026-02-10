<?php

namespace App\Ingest\Port;

interface ScanStateStoreInterface
{
    /**
     * @return array{path:string,size:int,mtime:\DateTimeImmutable,stable_count:int,status:string,first_seen_at:\DateTimeImmutable,last_seen_at:\DateTimeImmutable}
     */
    public function recordDetectedFile(string $path, int $size, \DateTimeImmutable $mtime, \DateTimeImmutable $scannedAt): array;
}

