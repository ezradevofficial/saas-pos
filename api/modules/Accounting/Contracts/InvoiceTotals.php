<?php

namespace Modules\Accounting\Contracts;

use App\Core\Rbac\ModuleRegistry;
use Brick\Math\BigInteger;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Models\Document;

/**
 * Read contract (docs/modules/crm.md, CRM-30): what a company invoiced in
 * a period, in its base currency, for another module's figures (CRM sales
 * targets), so it never reads `acc_*` tables itself: posted customer
 * invoices less posted customer credit notes by issue date, optionally
 * only those created by some users and/or at a branch. Null while
 * Accounting is off. The caller decides who may see the figure.
 */
final class InvoiceTotals
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  list<string>|null  $createdBy  null: anyone
     * @return string|null minor units of the company's base currency
     */
    public function posted(string $companyId, string $from, string $to, ?array $createdBy = null, ?string $branchId = null): ?string
    {
        if (! $this->modules->isActive(AccountingServiceProvider::MODULE)) {
            return null;
        }

        if ($createdBy === []) {
            return '0';
        }

        $sum = fn (string $kind) => (string) Document::query()->where('company_id', $companyId)->where('kind', $kind)->where('status', Document::POSTED)
            ->whereBetween('issue_date', [$from, $to])
            ->when($createdBy !== null, fn ($q) => $q->whereIn('created_by', $createdBy))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('base_total_minor');

        return (string) BigInteger::of($sum('customer_invoice'))->minus($sum('customer_credit'));
    }
}
