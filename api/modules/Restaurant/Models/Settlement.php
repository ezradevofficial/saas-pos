<?php

namespace Modules\Restaurant\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-16: a POS sale that paid (part of) an order; one per sale. */
class Settlement extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_settlements';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['line_ids' => 'array', 'final' => 'boolean', 'settled_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
