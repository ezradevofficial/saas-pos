<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-12: a bank, mobile money or cash account and the GL account it posts to. */
class BankAccount extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const KINDS = ['bank', 'mobile_money', 'cash'];

    protected $table = 'acc_bank_accounts';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'bank_account';

    protected $guarded = ['id', 'tenant_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
