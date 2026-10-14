<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-02: the accounts each role posts to, one row per company; audited as `accounting.settings.*`. */
class AccountingSettings extends Model implements HasScope
{
    use Audited, BelongsToTenant, CompanyRecord, HasUuids;

    /** The roles an account can play. */
    public const ROLES = ['receivable', 'payable', 'sales_tax', 'purchase_tax', 'fx_gain', 'fx_loss', 'rounding', 'retained_earnings', 'suspense'];

    protected $table = 'acc_settings';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'settings';

    protected $guarded = ['id', 'tenant_id'];

    public function accountFor(string $role): ?string
    {
        return $this->getAttribute("{$role}_account_id");
    }
}
