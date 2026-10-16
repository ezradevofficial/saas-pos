<?php

namespace Modules\Ecommerce\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ECOM-27: the delivery of an order by a rider. */
class Delivery extends EcomModel
{
    protected $table = 'ecom_deliveries';

    protected string $auditResource = 'delivery';

    protected function casts(): array
    {
        return ['flags' => 'array', 'assigned_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'located_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime', 'cod_due_minor' => 'integer', 'cod_collected_minor' => 'integer'];
    }

    public const UNASSIGNED = 'unassigned';

    public const ASSIGNED = 'assigned';

    public const PICKED_UP = 'picked_up';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}
