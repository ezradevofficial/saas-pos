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

/**
 * PHA-14, PHA-15, PHA-28: a prescription record (personal data). Archived,
 * never deleted (TEN-06). A till names its id (UUID v7 made on the device).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $prescriber_name
 * @property string $prescriber_licence
 * @property string $facility
 * @property string $patient_name
 * @property ?string $patient_phone
 * @property ?CarbonImmutable $patient_birth_date
 * @property ?string $patient_party_id
 * @property CarbonImmutable $prescribed_on
 * @property ?string $notes
 * @property string $status
 * @property string $source
 * @property ?string $sale_id
 * @property ?string $image_disk
 * @property ?string $image_path
 * @property ?string $image_name
 * @property ?string $image_mime
 * @property ?int $image_size
 * @property ?CarbonImmutable $archived_at
 */
class Prescription extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const VALID = 'valid';

    public const CANCELLED = 'cancelled';

    public const TILL = 'till';

    public const OFFICE = 'office';

    protected $table = 'pharmacy_prescriptions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'patient_birth_date' => 'immutable_date',
            'prescribed_on' => 'immutable_date',
            'image_size' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null;
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

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'patient_party_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
