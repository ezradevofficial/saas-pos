<?php

namespace Modules\Loyalty\Models;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-17: a stored statement of a credit account for a period (printed as `party.statement`). */
class Statement extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'loyalty_statements';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['ageing' => 'array', 'period_from' => 'immutable_date', 'period_to' => 'immutable_date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
