<?php

namespace App\Core\Imports;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\RecordUsage;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * The request checks every row first (nothing held) and only then marks
 * the import UNDOING. The job then undoes the rows, last first, in
 * committed chunks of `imports.undo_chunk` (500): each chunk takes the
 * importer's locks, then the audit chain head once (Auditor::batch), locks
 * the records it created FOR UPDATE (a sale racing to use one waits, then
 * is seen), checks the chunk's rows again and undoes them as the undoer,
 * through the entity's own requests and writers. So the tenant's audit
 * chain is never held for a whole large undo.
 *
 * Should a chunk find something depending (a sale made meanwhile, a row
 * the undoer may not undo), the undo stops there: the rows undone so far
 * stay undone (`import_rows.undone_at`), the import is PARTLY_UNDONE with
 * the blocking rows, and may be undone again (the rest) within 24 hours.
 * Every entry carries the import's id; `core.import.undo` (or
 * `core.import.undo_partial`) sums it up.
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
        private readonly ImportAccess $access,
    ) {}

    /**
     * What depends on the rows not undone yet (all of them, or $rows).
     *
     * @param  Collection<int, ImportRow>|null  $rows
     * @return array{count: int, rows: list<array{row: int, record: string, reason: string}>}
     */
    public function blockers(Import $import, ?Collection $rows = null): array
    {
        $blocking = [];
        $count = 0;
        $collect = function (Collection $rows) use ($import, &$blocking, &$count) {
            foreach ($this->dependents($import, $rows) as $entry) {
                $count++;

                if (count($blocking) < self::SHOWN) {
                    $blocking[] = $entry;
                }
            }
        };

        if ($rows !== null) {
            $collect($rows);
        } else {
            $this->pending($import)->orderBy('row_number')->chunk(1000, $collect);
        }

        return ['count' => $count, 'rows' => $blocking];
    }

    /** The job: undo in committed chunks, stopping at the first chunk something depends on. */
    public function undo(Import $import, ?User $by): void
    {
        if ($by === null || ! $by->isActive() || ! $this->access->undo($by, $import)) {
            $this->stop($import, [], __('imports.errors.undo_failed'));

            return;
        }

        $importer = $this->registry->get($import->importer);
        $context = new ImportContext($by, $import->mode);
        $chunk = max(1, (int) config('imports.undo_chunk', 500));

        while (true) {
            $outcome = $this->audit->actingAs($by->id, ['import_id' => $import->id], fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($import, $importer, $context, $chunk) {
                // Lock order, as the API's writers: the importer's locks, then the audit head.
                $importer->lockForBatch();

                return $this->auditor->batch(function () use ($import, $importer, $context, $chunk) {
                    $rows = $this->pending($import)->orderByDesc('row_number')->limit($chunk)->lockForUpdate()->get();

                    if ($rows->isEmpty()) {
                        return ['done' => true, 'blockers' => []];
                    }

                    // A sale using a created record takes a key share lock on it: lock
                    // them first, so a racing sale either committed (seen below) or waits.
                    $model = $importer->model();
                    $created = $rows->where('action', 'created')->pluck('record_id')->all();

                    if ($created !== []) {
                        $model::query()->whereIn('id', $created)->orderBy('id')->lockForUpdate()->pluck('id');
                    }

                    $blockers = $this->dependents($import, $rows);

                    if ($blockers !== []) {
                        return ['done' => false, 'blockers' => $blockers];
                    }

                    foreach ($rows as $row) {
                        try {
                            DB::connection(TenantContext::CONNECTION)->transaction(fn () => $importer->undo($row, $context));
                        } catch (Throwable $e) {
                            $rejected = RowRejected::from($e) ?? throw $e;

                            // Rows undone before this one in the chunk commit; the rest wait.
                            return ['done' => false, 'blockers' => [[
                                'row' => $row->row_number,
                                'record' => $this->describe($importer, $row),
                                'reason' => $rejected->getMessage(),
                            ]]];
                        }

                        ImportRow::query()->whereKey($row->id)->update(['undone_at' => now()->format('Y-m-d H:i:s.uP')]);
                    }

                    return ['done' => false, 'blockers' => []];
                });
            }));

            if ($outcome['done']) {
                $this->finish($import, $by);

                return;
            }

            if ($outcome['blockers'] !== []) {
                $this->stop($import, $outcome['blockers']);

                return;
            }
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ImportRow> */
    private function pending(Import $import)
    {
        return ImportRow::query()->where('import_id', $import->id)->whereNull('undone_at');
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     * @return list<array{row: int, record: string, reason: string}>
     */
    private function dependents(Import $import, Collection $rows): array
    {
        $importer = $this->registry->get($import->importer);
        $model = $importer->model();
        $morph = (new $model)->getMorphClass();
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
            return [];
        }

        $records = $model::query()->whereIn('id', array_keys($reasons))->get()->keyBy(fn ($record) => (string) $record->getKey());
        $entries = [];

        foreach ($rows->sortBy('row_number') as $row) {
            if (isset($reasons[$row->record_id])) {
                $record = $records->get($row->record_id);
                $entries[] = [
                    'row' => $row->row_number,
                    'record' => $record === null ? '' : $importer->describe($record),
                    'reason' => __('imports.usage.'.$reasons[$row->record_id]),
                ];
            }
        }

        return $entries;
    }

    private function describe(Importer $importer, ImportRow $row): string
    {
        $model = $importer->model();
        $record = $model::query()->find($row->record_id);

        return $record === null ? '' : $importer->describe($record);
    }

    private function finish(Import $import, User $by): void
    {
        if ($import->transition([Import::UNDOING], Import::UNDONE, ['undone_at' => now(), 'undone_by' => $by->id, 'blocking' => [], 'failure' => null])) {
            $this->audit->actingAs($by->id, ['import_id' => $import->id], fn () => $this->auditor->record('core.import.undo', $import, null, $import->summary()));
        }
    }

    /**
     * Stopped part way (or refused before starting): rows undone stay
     * undone; the import shows which rows blocked the rest.
     *
     * @param  list<array<string, mixed>>  $blockers
     */
    private function stop(Import $import, array $blockers, ?string $failure = null): void
    {
        $undone = ImportRow::query()->where('import_id', $import->id)->whereNotNull('undone_at')->count();
        $status = $undone > 0 ? Import::PARTLY_UNDONE : $import->finishedStatus();

        if ($import->transition([Import::UNDOING], $status, ['blocking' => array_slice($blockers, 0, self::SHOWN), 'failure' => $failure])) {
            $this->auditor->record('core.import.undo_partial', $import, null, [...$import->summary(), 'undone_rows' => $undone, 'blocking' => count($blockers)]);
        }
    }
}
