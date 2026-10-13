<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IMP-01..IMP-03: one uploaded file and what became of it.
 *
 * ready → checking → checked (the dry run) → importing → completed | failed
 * → undoing → undone (IMP-02). Changing the mapping or mode goes back to
 * ready. One dry run, import or undo runs at a time per tenant and
 * importer (the `imports_one_running` index). Never deleted; status
 * changes are audited as `core.import.*` (AUD-01).
 *
 * @property array<string, int> $mapping column key => header index
 * @property list<string> $headers
 */
class Import extends Model
{
    use BelongsToTenant, HasUuids;

    public const READY = 'ready';

    public const CHECKING = 'checking';

    public const CHECKED = 'checked';

    public const IMPORTING = 'importing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const UNDOING = 'undoing';

    public const UNDONE = 'undone';

    /** IMP-02: an undo stopped part way (something came to depend on the rest); the rows undone stay undone. */
    public const PARTLY_UNDONE = 'partly_undone';

    public const STATUSES = [self::READY, self::CHECKING, self::CHECKED, self::IMPORTING, self::COMPLETED, self::FAILED, self::UNDOING, self::PARTLY_UNDONE, self::UNDONE];

    /** Imports whose batch may (still) be undone. */
    public const UNDOABLE = [self::COMPLETED, self::FAILED, self::PARTLY_UNDONE];

    public const RUNNING = [self::CHECKING, self::IMPORTING, self::UNDOING];

    public const MODES = ['create', 'upsert'];

    /** IMP-02: an import may be undone this long after it finished. */
    public const UNDO_HOURS = 24;

    /** Microseconds kept: undo compares started_at with audit entries (IMP-02). */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'user_id', 'importer', 'mode', 'status', 'locale', 'file_disk', 'file_path', 'file_name', 'file_format', 'file_size',
        'headers', 'mapping', 'total_rows', 'processed_rows', 'created_rows', 'updated_rows', 'failed_rows', 'checked',
        'error_file_path', 'result_file_path', 'failure', 'blocking', 'started_at', 'finished_at', 'undone_at', 'undone_by',
        'run_token', 'claimed_at', 'scope_companies',
    ];

    protected $attributes = [
        'mode' => 'create',
        'headers' => '[]',
        'mapping' => '{}',
        'blocking' => '[]',
        'scope_companies' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => JsonObject::class,
            'blocking' => 'array',
            'scope_companies' => 'array',
            'checked' => 'boolean',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'failed_rows' => 'integer',
            'file_size' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /** The mapping and mode may change, and a dry run or the import may start. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::READY, self::CHECKED], true);
    }

    /** IMP-02: until when the batch may be undone, or null when it can't be. */
    public function undoUntil(): ?CarbonImmutable
    {
        if (! in_array($this->status, self::UNDOABLE, true) || $this->finished_at === null) {
            return null;
        }

        return $this->finished_at->addHours(self::UNDO_HOURS);
    }

    /** Where an import returns when an undo is refused: failed when it stopped short of the end. */
    public function finishedStatus(): string
    {
        return $this->processed_rows < $this->total_rows ? self::FAILED : self::COMPLETED;
    }

    /**
     * Change the status only if it still is one of $from (another worker,
     * or a request, may have moved it on): a stale worker never overwrites
     * a later state such as undone or completed. True when it changed.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $attributes
     */
    public function transition(array $from, string $to, array $attributes = []): bool
    {
        $values = [...$attributes, 'status' => $to];
        $model = (new self)->forceFill($values);
        $changed = self::query()->whereKey($this->id)->whereIn('status', $from)
            ->update([...array_intersect_key($model->getAttributes(), $values), 'updated_at' => $this->freshTimestampString()]);

        if ($changed === 1) {
            $this->refresh();
        }

        return $changed === 1;
    }

    /**
     * IMP-03: a worker takes the run named by $token, once. A second
     * delivery of the same job (or a job of an earlier run) finds it taken.
     */
    public function claim(string $token, string $running): bool
    {
        return self::query()->whereKey($this->id)->where('status', $running)->where('run_token', $token)->whereNull('claimed_at')
            ->update(['claimed_at' => now()->format($this->getDateFormat())]) === 1;
    }

    public function canBeUndone(): bool
    {
        $until = $this->undoUntil();

        return $until !== null && $until->isFuture() && ($this->created_rows + $this->updated_rows) > 0;
    }

    /** @return array<string, mixed> counts and timing for audit entries */
    public function summary(): array
    {
        return [
            'importer' => $this->importer,
            'mode' => $this->mode,
            'file' => $this->file_name,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'created_rows' => $this->created_rows,
            'updated_rows' => $this->updated_rows,
            'failed_rows' => $this->failed_rows,
        ];
    }
}
