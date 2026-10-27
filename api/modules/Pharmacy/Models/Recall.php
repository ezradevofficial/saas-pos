<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PHA-22: a recall of one batch number of an item, tenant-wide.
 *
 * @property string $id
 * @property string $item_id
 * @property string $batch_number
 * @property ?string $reference
 * @property string $reason
 * @property string $status
 * @property CarbonImmutable $issued_at
 * @property ?CarbonImmutable $lifted_at
 */
class Recall extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const LIFTED = 'lifted';

    protected $table = 'pharmacy_recalls';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'lifted_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lifted_by');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'recall_id');
    }
}
