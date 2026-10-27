<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-05: a chair, basin, room or bed of a branch.
 *
 * @property string $company_id
 * @property string $branch_id
 * @property string $name
 * @property string $kind
 */
class Resource extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_resources';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'resource';

    protected $guarded = [];

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }
}
