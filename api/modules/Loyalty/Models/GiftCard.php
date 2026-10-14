<?php

namespace Modules\Loyalty\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOY-20..LOY-24: a gift card or voucher. Only the code's SHA-256 hash and
 * its last four characters are kept (the code is shown once, at issue).
 * `balance_minor` caches the ledger's sum, written by GiftCardLedger under
 * the card's row lock. Issue, sale, block and unblock are audited.
 */
class GiftCard extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const GIFT_CARD = 'gift_card';

    public const VOUCHER = 'voucher';

    public const INACTIVE = 'inactive';

    public const ACTIVE = 'active';

    public const BLOCKED = 'blocked';

    public const STATUSES = [self::INACTIVE, self::ACTIVE, self::BLOCKED];

    protected $table = 'loyalty_gift_cards';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['code_hash', 'sync_xid', 'sync_seq'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GiftCardEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isExpired(?string $today = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->format('Y-m-d') < ($today ?? now()->format('Y-m-d'));
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
