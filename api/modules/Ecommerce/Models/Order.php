<?php

namespace Modules\Ecommerce\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** ECOM-16: an online, phone or WhatsApp order. */
class Order extends EcomModel
{
    protected $table = 'ecom_orders';

    protected string $auditResource = 'order';

    protected $hidden = ['tracking_token_hash', 'tracking_token_secret', 'delivery_code_hash', 'body_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['address' => 'array', 'tracking_token_secret' => 'encrypted', 'promotions' => 'array', 'flags' => 'array', 'fx' => FxSnapshot::class.':fx', 'return_fee' => 'boolean', 'whatsapp_opt_in' => 'boolean', 'reserved_until' => 'immutable_datetime', 'placed_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'picking_at' => 'immutable_datetime', 'ready_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime', 'subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer', 'delivery_fee_minor' => 'integer', 'delivery_tax_minor' => 'integer', 'total_minor' => 'integer', 'loyalty_paid_minor' => 'integer', 'due_minor' => 'integer', 'base_total_minor' => 'integer'];
    }

    public const PLACED = 'placed';

    public const ACCEPTED = 'accepted';

    public const PICKING = 'picking';

    public const READY = 'ready';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    public const STATUSES = [self::PLACED, self::ACCEPTED, self::PICKING, self::READY, self::OUT_FOR_DELIVERY, self::DELIVERED, self::CANCELLED, self::RETURNED];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'cod', 'cod_collected', 'failed', 'refund_due', 'refunded'];

    /** Statuses before the order leaves the location (it may still be cancelled). */
    public const OPEN = [self::PLACED, self::ACCEPTED, self::PICKING, self::READY];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('at')->orderBy('id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function flag(string $code, array $detail = []): void
    {
        $flags = $this->flags ?? [];
        $flags[] = array_filter(['code' => $code, 'detail' => $detail ?: null]);
        $this->flags = $flags;
    }
}
