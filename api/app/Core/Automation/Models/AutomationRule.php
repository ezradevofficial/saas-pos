<?php

namespace App\Core\Automation\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An automation rule (AUTO-01..AUTO-03): when `trigger` happens to a
 * document of `document_type` (in `company_id`, or any company when null)
 * and `conditions` hold, run `actions` in order. Archived, never deleted
 * (TEN-06). Every edit raises `version`; runs record the version they ran.
 * `webhook_secret` (encrypted) signs webhook calls and is never returned.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $document_type
 * @property ?string $company_id
 * @property string $trigger_type
 * @property array $trigger
 * @property ?array $conditions
 * @property array $actions
 * @property bool $enabled
 * @property int $version
 * @property ?string $webhook_secret
 * @property ?Carbon $next_run_at
 * @property ?string $created_by
 * @property ?string $updated_by
 */
class AutomationRule extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'name', 'document_type', 'company_id', 'trigger_type', 'trigger', 'conditions', 'actions',
        'enabled', 'version', 'webhook_secret', 'next_run_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['webhook_secret'];

    protected $attributes = ['enabled' => false, 'version' => 1];

    protected function casts(): array
    {
        return [
            'trigger' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'enabled' => 'boolean',
            'version' => 'integer',
            'webhook_secret' => 'encrypted',
            'next_run_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
    }

    /** Enabled and not archived: the rules triggers fire. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('enabled', true)->whereNull('archived_at');
    }

    /** The definition the validator and runner read. */
    public function definition(): array
    {
        return [
            'trigger' => $this->trigger,
            'conditions' => $this->conditions,
            'actions' => $this->actions,
        ];
    }

    /** Who the rule's actions act as: its last editor (stage moves, drafts, field writes). */
    public function actorId(): ?string
    {
        return $this->updated_by ?? $this->created_by;
    }
}
