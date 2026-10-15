<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HR-28: an onboarding or offboarding checklist of an employee. */
class Checklist extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['onboarding', 'offboarding'];

    protected $table = 'hr_checklists';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'checklist';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }
}
