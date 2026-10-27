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

/** REST-08: a kitchen, bar or other station of a location. */
class Station extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_stations';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'station';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(StationRoute::class, 'station_id');
    }
}
