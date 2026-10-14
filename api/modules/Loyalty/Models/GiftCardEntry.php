<?php

namespace Modules\Loyalty\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-20..LOY-25: one row of a gift card's ledger (append-only). */
class GiftCardEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['load', 'redeem', 'reverse_redeem', 'refund_credit', 'adjust', 'expire'];

    protected $table = 'loyalty_gift_card_entries';

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
