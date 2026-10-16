<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FA-04, FA-05, FA-11: an asset of the register, its depreciation terms
 * and its book (base minor units). Scoped by its location (RBAC-04).
 * Audited as `fixed_assets.asset.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property ?string $category_id
 * @property string $name
 * @property string $status
 * @property ?string $custodian_user_id
 * @property ?string $custodian_employee_id
 * @property ?string $cost_centre_id
 * @property string $currency
 * @property ?string $base_currency
 * @property ?string $method
 * @property ?int $useful_life_months
 * @property ?string $depreciated_to
 * @property bool $opening
 * @property string $source
 */
class Asset extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::DISPOSED];

    public const MANUAL = 'manual';

    public const IMPORT = 'import';

    public const FROM_RECEIPT = 'procurement_receipt';

    public const FROM_INVOICE = 'procurement_invoice';

    /** Money fields hidden without `fixed_assets.value.view` (RBAC-05). */
    public const VALUE_FIELDS = ['cost', 'base_cost', 'residual', 'book_cost', 'accumulated', 'impairment', 'surplus', 'book_value'];

    protected $table = 'fa_assets';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'asset';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'immutable_date',
            'in_service_on' => 'immutable_date',
            'warranty_until' => 'immutable_date',
            'depreciated_to' => 'immutable_date',
            'disposed_on' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'fx' => FxSnapshot::class.':fx',
            'custom' => 'array',
            'opening' => 'boolean',
            'useful_life_months' => 'integer',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    /** The net book value in base minor units. */
    public function bookValue(): int
    {
        return (int) $this->book_cost_minor - (int) $this->accumulated_minor - (int) $this->impairment_minor;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function custodianUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssetDocument::class)->orderByDesc('created_at')->orderBy('id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class)->orderByDesc('read_on')->orderByDesc('created_at');
    }
}
