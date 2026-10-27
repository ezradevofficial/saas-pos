<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** VAN-07, VAN-08: a load sheet (an Inventory transfer warehouse to van). */
class Load extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_loads';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'load';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->to_location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LoadLine::class, 'load_id');
    }
}
