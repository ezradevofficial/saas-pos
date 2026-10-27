<?php

namespace Modules\VanSales\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-13: the route credit ledger. */
class CreditEntry extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_credit_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
