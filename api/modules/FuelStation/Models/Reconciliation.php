<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FUEL-24, FUEL-26: a dip compared with the tank's book stock. Audited by Reconciliations. */
class Reconciliation extends Model
{
    use BelongsToTenant, HasUuids;

    public const BASELINE = 'baseline';

    public const ACCEPTED = 'accepted';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'fuel_reconciliations';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'baseline_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(Tank::class, 'tank_id');
    }

    public function dip(): BelongsTo
    {
        return $this->belongsTo(Dip::class, 'dip_id');
    }
}
