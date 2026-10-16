<?php

namespace Modules\CRM\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM-04: a sales team of a company (optionally of one branch) with a
 * leader and members; `turn` is the round-robin position (CRM-11).
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property string $name
 * @property ?string $leader_id
 * @property int $turn
 */
class Team extends CrmModel
{
    use Archivable;

    protected $table = 'crm_teams';

    protected function casts(): array
    {
        return ['turn' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class)->orderBy('user_id');
    }
}
