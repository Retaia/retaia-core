<?php

namespace App\Controller\Api;

use App\Storage\BusinessStorageRegistryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OpsReadinessReportBuilder
{
    private const MAX_SELF_HEALING_SECONDS = 300;

    public function __construct(
        private Connection $connection,
        private BusinessStorageRegistryInterface $storageRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{status: string, self_healing: array{active: bool, deadline_at: ?string, max_self_healing_seconds: int}, checks: list<array{name: string, status: string, message: string}>}
     */
    public function build(): array
    {
        $checks = [];

        $databaseOk = false;
        try {
            $databaseOk = (string) $this->connection->fetchOne('SELECT 1') === '1';
        } catch (\Throwable) {
            $databaseOk = false;
        }
        $checks[] = [
            'name' => 'database',
            'status' => $databaseOk ? 'ok' : 'fail',
            'message' => $databaseOk
                ? $this->translator->trans('readiness.message.database_ok')
                : $this->translator->trans('readiness.message.database_fail'),
        ];

        $missing = [];
        $notWritable = [];
        try {
            foreach ($this->storageRegistry->all() as $definition) {
                foreach ($definition->storage->managedDirectories() as $folder) {
                    $qualified = sprintf('%s:%s', $definition->id, $folder);
                    if (!$definition->storage->directoryExists($folder)) {
                        $missing[] = $qualified;
                        continue;
                    }

                    if (!$definition->storage->probeWritableDirectory($folder)) {
                        $notWritable[] = $qualified;
                    }
                }
            }
        } catch (\Throwable $e) {
            $missing[] = $e->getMessage();
        }

        $watchPathOk = $missing === [];
        $checks[] = [
            'name' => 'ingest_watch_path',
            'status' => $watchPathOk ? 'ok' : 'fail',
            'message' => $watchPathOk
                ? $this->translator->trans('readiness.message.watch_path_ok')
                : $this->translator->trans('readiness.message.watch_path_fail_prefix').implode(' | ', $missing),
        ];

        $storageWritableOk = $notWritable === [] && $watchPathOk;
        $checks[] = [
            'name' => 'storage_writable',
            'status' => $storageWritableOk ? 'ok' : 'fail',
            'message' => $storageWritableOk
                ? $this->translator->trans('readiness.message.storage_writable_ok')
                : $this->translator->trans('readiness.message.storage_writable_fail_prefix').implode(' | ', $notWritable),
        ];

        $status = 'ok';
        $selfHealing = [
            'active' => false,
            'deadline_at' => null,
            'max_self_healing_seconds' => self::MAX_SELF_HEALING_SECONDS,
        ];
        if (!$databaseOk) {
            $status = 'down';
        } elseif (!$watchPathOk || !$storageWritableOk) {
            $canSelfHeal = $notWritable === [];
            if ($canSelfHeal) {
                $status = 'degraded';
                $selfHealing['active'] = true;
                $selfHealing['deadline_at'] = (new \DateTimeImmutable(sprintf('+%d seconds', self::MAX_SELF_HEALING_SECONDS)))->format(DATE_ATOM);
            } else {
                $status = 'down';
            }
        }

        return [
            'status' => $status,
            'self_healing' => $selfHealing,
            'checks' => $checks,
        ];
    }
}
