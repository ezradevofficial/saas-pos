<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-03: a route and the weekdays it is visited. */
class Route extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_routes';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'route';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visit_days' => 'array',
        ];
    }

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }
}
