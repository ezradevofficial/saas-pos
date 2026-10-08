<?php

namespace App\Core\Automation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of a rule (AUTO-05): the rule version, the trigger, the document,
 * the outcome, each action's result, a safe error message, attempts, and
 * the chain it belongs to (AUTO-06).
 *
 * Outcomes: queued, running and retrying while in progress; then
 * succeeded, skipped (conditions false, or the rule was switched off
 * before it ran), failed (after the last attempt), throttled (rate limit)
 * or loop_blocked (the rule would trigger itself, or the chain is too long).
 *
 * @property string $id
 * @property string $rule_id
 * @property int $rule_version
 * @property string $trigger_type
 * @property array $trigger
 * @property ?string $document_type
 * @property ?string $document_id
 * @property string $outcome
 * @property ?array $conditions
 * @property array $actions
 * @property ?string $error
 * @property ?string $error_code
 * @property int $attempts
 * @property string $chain_id
 * @property int $depth
 * @property array $chain
 */
class AutomationRun extends Model
{
    use BelongsToTenant, HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const RETRYING = 'retrying';

    public const SUCCEEDED = 'succeeded';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const THROTTLED = 'throttled';

    public const LOOP_BLOCKED = 'loop_blocked';

    public const OUTCOMES = [self::QUEUED, self::RUNNING, self::RETRYING, self::SUCCEEDED, self::SKIPPED, self::FAILED, self::THROTTLED, self::LOOP_BLOCKED];

    protected $fillable = [
        'rule_id', 'rule_version', 'trigger_type', 'trigger', 'document_type', 'document_id', 'outcome',
        'conditions', 'actions', 'error', 'error_code', 'attempts', 'chain_id', 'depth', 'chain', 'dedupe_key',
        'started_at', 'finished_at', 'next_attempt_at',
    ];

    protected $attributes = ['trigger' => '{}', 'actions' => '[]', 'chain' => '[]', 'attempts' => 0];

    protected function casts(): array
    {
        return [
            'rule_version' => 'integer',
            'trigger' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'attempts' => 'integer',
            'depth' => 'integer',
            'chain' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    public function isFinished(): bool
    {
        return ! in_array($this->outcome, [self::QUEUED, self::RUNNING, self::RETRYING], true);
    }
}
