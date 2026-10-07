<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Whether a tenant has an optional module active (RBAC-08). */
class TenantModule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected string $auditResource = 'module';

    protected $fillable = ['module', 'status', 'activated_at', 'deactivated_at'];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }
}
