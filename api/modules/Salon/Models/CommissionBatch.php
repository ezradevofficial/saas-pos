<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-23: a period's commissions, reviewed and approved before export.
 *
 * @property string $company_id
 * @property string $number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $currency
 * @property string $status
 * @property string $created_by
 * @property ?string $submitted_by
 * @property ?string $decided_by
 * @property ?string $exported_by
 * @property ?string $export_mode
 */
class CommissionBatch extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const EXPORTED = 'exported';

    protected $table = 'salon_commission_batches';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'commission_batch';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'exported_at' => 'immutable_datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
