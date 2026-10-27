<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-17: a collection of a customer's balance. */
class Collection extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_collections';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'collection';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
