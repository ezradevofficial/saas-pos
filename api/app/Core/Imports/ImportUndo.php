<?php

namespace App\Core\Imports;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\RecordUsage;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * IMP-02: undo a whole batch within 24 hours, when nothing depends on it.
 *
 * Something depends on a row when:
 * - a record it created is used: in a sale line, a price, a document
 *   (RecordUsage, each module answering for its own tables);
 * - the record (created or updated) changed after the import, outside it:
 *   any audit entry on it since the import started that does not carry
 *   the import's id (by anyone, so nothing done since is lost).
 *
 * If anything depends, undo is refused with the blocking rows. Otherwise,
 * in one transaction, created records are archived (never deleted,
 * TEN-06) and updated ones get their before image back, last row first.
 * Every entry carries the import's id; `core.import.undo` sums it up.
 */
class ImportUndo
{
    /** Blocking rows reported (the count says how many there are). */
    public const SHOWN = 100;

    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly RecordUsage $usage,
        private readonly Auditor $auditor,
        private readonly AuditContext $audit,
    ) {}

    /**
     * @return array{count: int, rows: list<array{row: int, record: string, reason: string}>}
     */
    public function blockers(Import $import): array
    {
        $importer = $this->registry->get($import->importer);
        $model = $importer->model();
        $morph = (new $model)->getMorphClass();
        $blocking = [];
        $count = 0;

        ImportRow::query()->where('import_id', $import->id)->orderBy('row_number')
            ->chunk(1000, function ($rows) use ($import, $importer, $model, $morph, &$blocking, &$count) {
                $ids = $rows->pluck('record_id')->all();
                $created = $rows->where('action', 'created')->pluck('record_id')->all();
                $reasons = $importer->usageEntity() === null || $created === [] ? [] : $this->usage->used($importer->usageEntity(), $created);

                // Changed since, outside the import.
                $changed = DB::connection(TenantContext::CONNECTION)->table('audit_logs')
                    ->where('auditable_type', $morph)
                    ->whereIn('auditable_id', $ids)
                    ->where('occurred_at', '>', ($import->started_at ?? $import->created_at)->format('Y-m-d H:i:s.uP'))
                    ->whereRaw("coalesce(after -> 'metadata' ->> 'import_id', '') <> ?", [$import->id])
                    ->distinct()->pluck('auditable_id')->all();

                foreach ($changed as $id) {
                    $reasons[$id] ??= 'changed';
                }

                if ($reasons === []) {
                    return;
                }

                $count += count($reasons);
                $records = $model::query()->whereIn('id', array_keys($reasons))->get()->keyBy(fn ($record) => (string) $record->getKey());

                foreach ($rows as $row) {
                    if (isset($reasons[$row->record_id]) && count($blocking) < self::SHOWN) {
                        $record = $records->get($row->record_id);
                        $blocking[] = [
                            'row' => $row->row_number,
                            'record' => $record === null ? '' : $importer->describe($record),
                            'reason' => __('imports.usage.'.$reasons[$row->record_id]),
                        ];
                    }
                }
            });

        return ['count' => $count, 'rows' => $blocking];
    }

    /**
     * Undo the batch (the job). Checked again first, in the transaction:
     * a sale may have used a record since the request.
     *
     * @return array{count: int, rows: list<array<string, mixed>>}|null the blockers when refused
     */
    public function undo(Import $import, User $by): ?array
    {
        $importer = $this->registry->get($import->importer);
        $context = new ImportContext($by, $import->mode);

        return $this->audit->actingAs($by->id, ['import_id' => $import->id], fn () => $this->auditor->batch(function () use ($import, $importer, $context, $by) {
            $blockers = $this->blockers($import);

            if ($blockers['count'] > 0) {
                return $blockers;
            }

            ImportRow::query()->where('import_id', $import->id)->orderByDesc('row_number')
                ->chunk(500, function ($rows) use ($importer, $context) {
                    foreach ($rows as $row) {
                        $importer->undo($row, $context);
                    }
                });

            $import->forceFill(['status' => Import::UNDONE, 'undone_at' => now(), 'undone_by' => $by->id, 'blocking' => []])->save();
            $this->auditor->record('core.import.undo', $import, null, $import->summary());

            return null;
        }));
    }
}
