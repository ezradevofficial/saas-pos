<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a tenant has an optional module active (RBAC-08). SUB-07: a
 * module switched off keeps its data; `switched_off_at` dates it and
 * `purge_flagged_at` marks it reported for purge (billing:purge-report).
 */
class TenantModule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected string $auditResource = 'module';

    protected $fillable = ['module', 'status', 'activated_at', 'deactivated_at', 'switched_off_at', 'purge_flagged_at'];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'switched_off_at' => 'datetime',
            'purge_flagged_at' => 'datetime',
        ];
    }
}
