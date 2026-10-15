<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** HR-17: a rotating roster of a branch or department. Scoped by its branch, else its company (HR-38). */
class Roster extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'hr_rosters';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'roster';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['anchor_date' => 'immutable_date'];
    }

    public function scope(): Scope
    {
        return $this->branch_id !== null ? Scope::branch($this->branch_id) : Scope::company($this->company_id);
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(Rotation::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(RosterMember::class)->orderBy('offset_days')->orderBy('id');
    }
}
