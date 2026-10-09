<?php

namespace Modules\POS\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * POS-01: a completed sale, as the till made it (ADR 004: the device wins
 * for completed sales; `flags` lists what the server noticed). Never
 * edited after upload except its status when voided (POS-05).
 */
class Sale extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    public const STATUSES = [self::COMPLETED, self::VOIDED];

    protected $table = 'pos_sales';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receipt_seq' => 'integer',
            'flags' => 'array',
            'offline' => 'boolean',
            'fx' => FxSnapshot::class.':fx',
            'sold_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderBy('created_at')->orderBy('id');
    }

    public function voidRecord(): HasOne
    {
        return $this->hasOne(SaleVoid::class)->where('status', '<>', 'rejected')->latest('received_at');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('refunded_at');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
