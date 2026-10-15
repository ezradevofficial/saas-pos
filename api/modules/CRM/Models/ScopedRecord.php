<?php

namespace Modules\CRM\Models;

use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-08: a record at a branch of a company, with an owner and an
 * optional team (leads, opportunities, quotes, activities). Permissions
 * apply at the branch (RBAC-04); who sees it follows CrmAccess.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property ?string $owner_id
 * @property ?string $team_id
 */
abstract class ScopedRecord extends CrmModel implements HasScope
{
    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
