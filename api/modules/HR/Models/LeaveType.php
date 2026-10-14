<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-12: a leave type of a company. Statutory types come from the country
 * pack with `needs_confirmation` until a person enters their days.
 *
 * @property string $company_id
 * @property string $code
 * @property string $accrual
 * @property ?string $entitlement_days
 * @property bool $needs_confirmation
 * @property string $count_basis
 * @property ?string $carry_over_max_days
 * @property bool $allow_negative
 * @property bool $paid
 */
class LeaveType extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const ACCRUALS = ['none', 'annual', 'monthly'];

    public const COUNT_BASES = ['working_days', 'calendar_days'];

    protected $table = 'hr_leave_types';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'leave_type';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean',
            'needs_confirmation' => 'boolean',
            'allow_negative' => 'boolean',
            'requires_document' => 'boolean',
            'accrues_from' => 'immutable_date',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    /** HR-12: usable for requests and accrual only once its days are confirmed. */
    public function usable(): bool
    {
        return ! $this->needs_confirmation;
    }
}
