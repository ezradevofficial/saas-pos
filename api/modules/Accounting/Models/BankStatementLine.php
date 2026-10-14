<?php

namespace Modules\Accounting\Models;

use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ACC-13, ACC-14: a line of a statement: money in (positive) or out (negative). */
class BankStatementLine extends Model implements HasScope
{
    use BelongsToTenant, CompanyRecord, HasUuids;

    public const UNMATCHED = 'unmatched';

    public const MATCHED = 'matched';

    protected $table = 'acc_bank_statement_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'matched_at' => 'immutable_datetime', 'amount_minor' => 'integer', 'balance_minor' => 'integer'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class, 'statement_line_id')->whereNull('unmatched_at');
    }
}
