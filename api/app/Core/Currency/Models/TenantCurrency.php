<?php

namespace App\Core\Currency\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A currency the tenant uses (CUR-01): its decimals (the catalogue default
 * unless changed while no amount in it was stored) and its cash rounding in
 * minor units (e.g. CDF 50). Deactivated rather than deleted. Audited as
 * `core.currency.*`.
 */
class TenantCurrency extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditResource = 'currency';

    protected $fillable = ['code', 'decimals', 'cash_rounding_minor', 'active'];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'cash_rounding_minor' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** RBAC-04: tenant currencies are tenant-wide settings. */
    public function scope(): Scope
    {
        return Scope::tenant();
    }
}
