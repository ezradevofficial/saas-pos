<?php

namespace Modules\Loyalty\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-14..LOY-16: one row of the credit ledger, positive when the customer owes more (append-only). */
class CreditEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['charge', 'payment', 'reverse_charge', 'refund_credit', 'adjust'];

    protected $table = 'loyalty_credit_entries';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
