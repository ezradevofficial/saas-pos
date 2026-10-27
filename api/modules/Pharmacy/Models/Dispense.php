<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-16, PHA-17, PHA-26: a sale line that needed a prescription or a
 * pharmacist (or is controlled): who approved it, against which
 * prescription, and what the server found when the sale arrived.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $sale_id
 * @property string $sale_line_id
 * @property ?string $receipt_number
 * @property string $item_id
 * @property string $class
 * @property string $qty
 * @property ?string $customer_id
 * @property ?string $prescription_id
 * @property ?string $pharmacist_id
 * @property ?string $cashier_id
 * @property string $approval_mode
 * @property list<string> $flags
 * @property string $review_status
 * @property CarbonImmutable $dispensed_at
 */
class Dispense extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const SELF = 'self';

    public const OVERRIDE = 'override';

    public const NONE = 'none';

    public const REVIEW_NONE = 'none';

    public const REVIEW_OPEN = 'open';

    public const REVIEWED = 'reviewed';

    protected $table = 'pharmacy_dispenses';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'dispensed_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacist_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
