<?php

namespace Modules\Loyalty\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOY-02, LOY-14: a customer's points or credit account at a company.
 * `balance` caches the ledger's sum (points, or minor units of `currency`
 * for credit), written only by the ledger services under the account's
 * row lock. Opening and closing are audited by the services.
 */
class Account extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const POINTS = 'points';

    public const CREDIT = 'credit';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'loyalty_accounts';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['sync_xid', 'sync_seq'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function pointsEntries(): HasMany
    {
        return $this->hasMany(PointsEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
