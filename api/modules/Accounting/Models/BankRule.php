<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-14: statement lines whose description contains a text go to an account (bank charges, M-Pesa fees). */
class BankRule extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const DIRECTIONS = ['in', 'out', 'any'];

    protected $table = 'acc_bank_rules';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'bank_rule';

    protected $guarded = ['id', 'tenant_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function applies(BankStatementLine $line): bool
    {
        if ($this->bank_account_id !== null && $this->bank_account_id !== $line->bank_account_id) {
            return false;
        }

        if (($this->direction === 'in' && $line->amount_minor < 0) || ($this->direction === 'out' && $line->amount_minor > 0)) {
            return false;
        }

        return mb_stripos($line->description.' '.$line->reference, $this->contains) !== false;
    }
}
