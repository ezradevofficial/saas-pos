<?php

namespace Modules\Pharmacy\Models;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PHA-06: a back-office receipt of lots at a location.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property ?string $supplier_id
 * @property ?string $reference
 * @property CarbonImmutable $received_at
 */
class Receipt extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_receipts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class, 'receipt_id')->orderBy('line_no');
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
