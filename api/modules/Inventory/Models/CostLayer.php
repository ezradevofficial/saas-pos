<?php

namespace Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * INV-12: a FIFO cost layer: what one receipt brought in and what of it is
 * still unconsumed. Consumed oldest first under the cost state's lock.
 *
 * @property string $qty
 * @property int|string $cost_minor
 * @property string $remaining_qty
 * @property int|string $remaining_cost_minor
 */
class CostLayer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_cost_layers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
