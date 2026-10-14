<?php

namespace Modules\Procurement\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-14: a goods received note at one location (RBAC-04: checked at the
 * location), valued in base currency at the receipt date's rate when
 * posted (CUR-04).
 *
 * @property string $location_id
 * @property string $order_id
 * @property string $party_id
 * @property CarbonImmutable $received_at
 * @property string $currency
 * @property ?FxSnapshot $fx
 * @property ?string $base_currency
 * @property int $value_minor
 * @property ?int $base_value_minor
 * @property int $landed_minor
 */
class Receipt extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::POSTED, self::CANCELLED];

    protected $table = 'procurement_receipts';

    protected string $auditResource = 'receipt';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'received_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'value_minor' => 'integer', 'base_value_minor' => 'integer', 'landed_minor' => 'integer',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('line_no');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
