<?php

namespace Modules\VanSales\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-10: a POS sale linked to its run. */
class VanSale extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_sales';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'sold_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location((string) Run::query()->whereKey($this->run_id)->value('location_id'));
    }
}
