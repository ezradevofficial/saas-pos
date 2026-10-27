<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-02: a van, a stock location with a rep and a device. */
class Van extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_vans';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'van';

    protected $guarded = [];

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
