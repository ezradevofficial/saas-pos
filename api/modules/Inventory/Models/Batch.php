<?php

namespace Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * INV-06: a batch of an item (number unique per item ignoring case) and
 * its expiry date. Made by the first receipt naming it.
 *
 * @property string $id
 * @property string $item_id
 * @property string $batch_number
 * @property ?CarbonImmutable $expires_on
 */
class Batch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'inventory_batches';

    protected $fillable = ['item_id', 'batch_number', 'expires_on'];

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date'];
    }
}
