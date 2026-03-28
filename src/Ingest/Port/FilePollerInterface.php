<?php

namespace App\Ingest\Port;

/**
 * @return list<array{storage_id:string,path:string,size:int,mtime:\DateTimeImmutable}>
 */
interface FilePollerInterface
{
    /**
     * @return list<array{storage_id:string,path:string,size:int,mtime:\DateTimeImmutable}>
     */
    public function poll(int $limit = 100): array;
}
