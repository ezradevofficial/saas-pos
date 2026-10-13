<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ACC-01: an account of a company's chart. Its name is stored once (the
 * tenant's language at creation, then the tenant's own). Headers are not
 * postable; control accounts take only subledger postings. Archived, never
 * deleted (TEN-06); audited as `accounting.account.*`.
 */
class Account extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Balance sheet types; the others are profit and loss. */
    public const BALANCE_SHEET = ['asset', 'liability', 'equity'];

    public const CASH_FLOWS = ['cash', 'operating', 'non_cash', 'investing', 'financing'];

    public const SUBTYPES = [
        'header', 'cash', 'bank', 'mobile_money', 'receivable', 'inventory', 'current_asset', 'fixed_asset', 'accumulated_depreciation',
        'intercompany', 'suspense', 'payable', 'tax', 'current_liability', 'long_term_liability', 'equity', 'retained_earnings',
        'revenue', 'other_income', 'cost_of_sales', 'expense', 'depreciation', 'fx',
    ];

    protected $table = 'acc_accounts';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'account';

    protected $fillable = [
        'company_id', 'code', 'name', 'type', 'subtype', 'parent_id', 'postable', 'currency', 'is_control', 'cash_flow', 'revalue',
        'counterparty_company_id', 'template_code',
    ];

    protected function casts(): array
    {
        return ['postable' => 'boolean', 'is_control' => 'boolean', 'revalue' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isBalanceSheet(): bool
    {
        return in_array($this->type, self::BALANCE_SHEET, true);
    }

    /** Debit-normal (assets, expenses) or credit-normal (liabilities, equity, income). */
    public static function debitNormal(string $type): bool
    {
        return in_array($type, ['asset', 'expense'], true);
    }
}
