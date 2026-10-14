<?php

namespace Modules\Procurement\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-13: a purchase order for one supplier, approved through the
 * `procurement.po` flow, in any currency with its rate and base amounts
 * frozen at submission (CUR-04).
 *
 * @property string $location_id
 * @property string $party_id
 * @property string $currency
 * @property ?FxSnapshot $fx
 * @property ?string $base_currency
 * @property bool $tax_inclusive
 * @property CarbonImmutable $order_date
 * @property ?CarbonImmutable $expected_on
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property ?int $base_total_minor
 * @property string $requested_by
 * @property ?array $custom
 */
class Order extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PARTIALLY_RECEIVED = 'partially_received';

    public const RECEIVED = 'received';

    public const CLOSED = 'closed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::PARTIALLY_RECEIVED, self::RECEIVED, self::CLOSED, self::REJECTED, self::CANCELLED];

    /** Statuses goods may be received and invoiced in. */
    public const RECEIVABLE = [self::APPROVED, self::PARTIALLY_RECEIVED];

    public const INVOICEABLE = [self::APPROVED, self::PARTIALLY_RECEIVED, self::RECEIVED];

    protected $table = 'procurement_orders';

    protected string $auditResource = 'po';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'custom' => 'array', 'tax_inclusive' => 'boolean',
            'order_date' => 'immutable_date', 'expected_on' => 'immutable_date', 'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'base_subtotal_minor' => 'integer', 'base_tax_minor' => 'integer', 'base_total_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
