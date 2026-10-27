<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-06: a van's selling day. */
class Run extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_runs';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'run';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'run_date' => 'date:Y-m-d',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
