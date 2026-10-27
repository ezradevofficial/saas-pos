<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-04: a customer stop of a route. */
class Stop extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_stops';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'stop';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visit_days' => 'array',
            'position' => 'integer',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
