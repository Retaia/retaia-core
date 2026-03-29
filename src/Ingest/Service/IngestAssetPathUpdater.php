<?php

namespace App\Ingest\Service;

use App\Asset\Repository\AssetRepositoryInterface;
use App\Entity\Asset;
use App\Ingest\Repository\PathAuditRepository;

final class IngestAssetPathUpdater
{
    public function __construct(
        private AssetRepositoryInterface $assets,
        private PathAuditRepository $audit,
    ) {
    }

    public function persistPathUpdate(Asset $asset, string $fromRelative, string $toRelative): void
    {
        $fields = $asset->getFields();
        $paths = is_array($fields['paths'] ?? null) ? $fields['paths'] : [];
        if (($paths['original_relative'] ?? null) === $toRelative) {
            return;
        }

        $history = $fields['path_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $last = $history[count($history) - 1] ?? null;
        if (is_array($last) && ($last['from'] ?? null) === $fromRelative && ($last['to'] ?? null) === $toRelative) {
            $paths['original_relative'] = $toRelative;
            $fields['paths'] = $paths;
            $asset->setFields($fields);
            $this->assets->save($asset);

            return;
        }

        $entry = [
            'from' => $fromRelative,
            'to' => $toRelative,
            'reason' => 'state_transition',
            'moved_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $history[] = $entry;
        $paths['original_relative'] = $toRelative;
        $fields['paths'] = $paths;
        $fields['path_history'] = $history;
        $asset->setFields($fields);
        $this->assets->save($asset);

        $this->audit->record($asset->getUuid(), $fromRelative, $toRelative, 'state_transition', new \DateTimeImmutable());
    }
}
