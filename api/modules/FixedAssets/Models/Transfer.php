<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-14: an asset moved to another place, custodian or cost centre, through its flow. Audited as `fixed_assets.transfer.*`. */
class Transfer extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_transfers';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'transfer';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['transfer_date' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromCostCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'from_cost_centre_id');
    }

    public function toCostCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'to_cost_centre_id');
    }

    public function fromCustodianUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_custodian_user_id');
    }

    public function toCustodianUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_custodian_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
