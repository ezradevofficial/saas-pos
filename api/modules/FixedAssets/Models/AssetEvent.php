<?php

namespace Modules\FixedAssets\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FA-26: one line of an asset's history (what changed, before and after).
 * Append-only; the change itself is audited where it happens (AUD-01).
 */
class AssetEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['created', 'activated', 'cancelled', 'estimate_changed', 'transferred', 'revalued', 'impaired', 'disposed', 'reading', 'maintenance', 'imported'];

    public const UPDATED_AT = null;

    protected $table = 'fa_asset_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['happened_on' => 'immutable_date', 'before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
