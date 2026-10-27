<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REST-02: a table with seats, a place on the plan and a manual status; `occupied` and `bill` are derived from its open orders. */
class Table extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_tables';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'table';

    protected $guarded = [];

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_id');
    }
}
