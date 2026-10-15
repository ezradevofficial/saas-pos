<?php

namespace Modules\CRM\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-11: who gets a lead that arrives without an owner. Matches on the
 * branch, sources and channels (any when empty); gives the lead to a user
 * or deals it round-robin in a team.
 *
 * @property string $company_id
 * @property string $name
 * @property int $position
 * @property ?string $branch_id
 * @property list<string> $source_ids
 * @property list<string> $channels
 * @property string $assignee_type
 * @property ?string $user_id
 * @property ?string $team_id
 * @property bool $active
 */
class AssignmentRule extends CrmModel
{
    use Archivable;

    protected $table = 'crm_assignment_rules';

    protected string $auditResource = 'assignment_rule';

    protected function casts(): array
    {
        return ['source_ids' => 'array', 'channels' => 'array', 'active' => 'boolean', 'position' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
