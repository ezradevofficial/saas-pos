<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-04, HR-10: a bank or mobile-money account an employee is paid into.
 * The number is encrypted at rest and never written to the audit log
 * (HR-11: `account_number` is audited as changed, by its hint).
 *
 * @property string $employee_id
 * @property string $kind
 * @property string $account_number
 * @property string $account_hint
 * @property bool $is_primary
 */
class PayAccount extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['bank', 'mobile_money'];

    protected $table = 'hr_pay_accounts';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'pay_account';

    protected array $auditHidden = ['account_number'];

    protected $guarded = [];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_primary' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
