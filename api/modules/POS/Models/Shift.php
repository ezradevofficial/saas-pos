<?php

namespace Modules\POS\Models;

use App\Core\Identity\Models\User;
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

/** POS-04: a cashier's shift on one device, with its cash per currency (balances). */
class Shift extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const STATUSES = [self::OPEN, self::CLOSED];

    protected $table = 'pos_shifts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'closed_received_at' => 'immutable_datetime',
            // POS-04: what the server noticed without refusing (opener_not_permitted, closer_not_permitted, placeholder); null when nothing.
            'flags' => 'array',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(ShiftBalance::class)->orderBy('currency');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('occurred_at');
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

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }
}
