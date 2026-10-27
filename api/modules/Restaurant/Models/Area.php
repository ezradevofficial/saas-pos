<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REST-02: an area of a location (Terrace, Main hall). */
class Area extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_areas';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'area';

    protected $guarded = [];

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'area_id');
    }
}
