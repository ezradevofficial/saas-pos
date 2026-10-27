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
 * SAL-03: a stylist, barber, therapist. Optionally a user (own calendar) and an HR employee (a plain id).
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property ?string $user_id
 * @property ?string $employee_id
 * @property string $name
 * @property ?string $title
 * @property string $color
 * @property bool $accepts_online
 */
class Staff extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_staff';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'staff';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['accepts_online' => 'boolean'];
    }

    public function scope(): Scope
    {
        return $this->branch_id !== null ? Scope::branch($this->branch_id) : Scope::company($this->company_id);
    }
}
