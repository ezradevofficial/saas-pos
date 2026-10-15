<?php

namespace Modules\Procurement\Models;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-17: charges (freight, duty, insurance) spread over GRN lines by
 * value, quantity or weight, in integer minor units of the base currency.
 *
 * @property CarbonImmutable $cost_date
 * @property string $method
 * @property ?string $description
 * @property ?string $base_currency
 * @property int $base_total_minor
 * @property string $created_by
 */
class LandedCost extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::POSTED, self::CANCELLED];

    public const METHODS = ['value', 'quantity', 'weight'];

    protected $table = 'procurement_landed_costs';

    protected string $auditResource = 'landed_cost';

    protected function casts(): array
    {
        return ['cost_date' => 'immutable_date', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'base_total_minor' => 'integer'];
    }

    public function charges(): HasMany
    {
        return $this->hasMany(LandedCostCharge::class)->orderBy('line_no');
    }

    public function receiptLinks(): HasMany
    {
        return $this->hasMany(LandedCostReceipt::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
