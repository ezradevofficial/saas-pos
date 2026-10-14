<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Dimensions\Department;
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
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * HR-02: an employee of a company. Not a user (an optional link, AUTH-12).
 * Its place (location, else branch, else company) is the scope every
 * permission is checked at (HR-38). Never deleted: terminated (HR-05).
 * Audited as `hr.employee.*` without the sensitive values (HR-11).
 *
 * @property string $id
 * @property string $number
 * @property string $company_id
 * @property ?string $branch_id
 * @property ?string $location_id
 * @property ?string $department_id
 * @property ?string $position_id
 * @property ?string $reports_to_id
 * @property ?string $user_id
 * @property string $first_name
 * @property ?string $middle_name
 * @property string $last_name
 * @property string $status
 * @property CarbonImmutable $hire_date
 * @property ?CarbonImmutable $termination_date
 * @property list<string> $clock_methods
 */
class Employee extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const TERMINATED = 'terminated';

    public const STATUSES = [self::ACTIVE, self::TERMINATED];

    public const GENDERS = ['female', 'male', 'other'];

    public const TERMINATION_REASONS = ['resignation', 'dismissal', 'end_of_contract', 'retirement', 'redundancy', 'death', 'other'];

    public const CLOCK_METHODS = ['web', 'till', 'device'];

    /** HR-11: sensitive columns never written to the audit log (their changes are recorded by name). */
    public const SENSITIVE_COLUMNS = ['date_of_birth', 'medical_notes'];

    protected $table = 'hr_employees';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'employee';

    protected array $auditHidden = self::SENSITIVE_COLUMNS;

    protected $guarded = [];

    protected $hidden = ['medical_notes'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'immutable_date',
            'termination_date' => 'immutable_date',
            'date_of_birth' => 'immutable_date',
            'address' => 'array',
            'emergency_contacts' => 'array',
            'clock_methods' => 'array',
            'custom' => 'array',
            // HR-10: encrypted at rest.
            'medical_notes' => 'encrypted',
        ];
    }

    public function scope(): Scope
    {
        return self::placeScope($this->company_id, $this->branch_id, $this->location_id);
    }

    public static function placeScope(string $companyId, ?string $branchId, ?string $locationId): Scope
    {
        return match (true) {
            $locationId !== null => Scope::location($locationId),
            $branchId !== null => Scope::branch($branchId),
            default => Scope::company($companyId),
        };
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(EmployeeIdentifier::class)->orderBy('type');
    }

    public function payAccounts(): HasMany
    {
        return $this->hasMany(PayAccount::class)->whereNull('archived_at')->orderByDesc('is_primary')->orderBy('created_at');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->orderByDesc('start_date');
    }

    /** HR-07: the active contract with the latest start. */
    public function currentContract(): HasOne
    {
        return $this->hasOne(Contract::class)->where('status', Contract::ACTIVE)->orderByDesc('start_date');
    }

    public function clockPin(): HasOne
    {
        return $this->hasOne(ClockPin::class);
    }
}
