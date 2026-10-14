<?php

namespace Modules\Loyalty\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-02..LOY-07: one row of the points ledger (append-only; a trigger refuses changes). */
class PointsEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['earn', 'redeem', 'reverse_earn', 'reverse_redeem', 'refund_credit', 'expire', 'adjust'];

    protected $table = 'loyalty_points_entries';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'occurred_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
