<?php

namespace Modules\Accounting\Contracts;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Models\Document;

/**
 * Read contract (docs/modules/crm.md, CRM-25): a customer's receivables
 * for another module's view (the CRM customer 360), so it never reads
 * `acc_*` tables itself. Answers only while Accounting is active, with
 * the customer invoices and credit notes of the companies where the
 * reader holds `accounting.invoice.view` (ACC-22, RBAC-04): open amounts
 * per currency (posted invoices less what was allocated), overdue ones
 * (due date passed in the company's time zone) and the latest documents.
 * Null when the reader may see none.
 */
final class CustomerReceivables
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ScopeResolver $resolver,
    ) {}

    /**
     * @return array{open: list<array{currency: string, open_minor: string}>, overdue: list<array{currency: string, open_minor: string}>, recent: list<array{id: string, number: ?string, kind: string, issue_date: string, due_date: string, currency: string, total_minor: string, open_minor: string, status: string}>}|null
     */
    public function forCustomer(User $reader, string $partyId, int $limit = 10): ?array
    {
        if (! $this->modules->isActive(AccountingServiceProvider::MODULE)) {
            return null;
        }

        $companies = Company::query()->whereIn('id', Document::query()->where('party_id', $partyId)->select('company_id'))->get()
            ->filter(fn (Company $company) => $reader->can('accounting.invoice.view', Scope::company($company->id)));

        if ($companies->isEmpty() && ! $this->resolver->can($reader, 'accounting.invoice.view')) {
            return null;
        }

        $ids = $companies->modelKeys();
        $documents = fn () => Document::query()->where('party_id', $partyId)->whereIn('company_id', $ids)->whereIn('kind', ['customer_invoice', 'customer_credit']);
        $open = $documents()->where('status', Document::POSTED)->where('kind', 'customer_invoice')->where('open_minor', '>', 0);
        $overdue = [];

        foreach ($companies as $company) {
            $today = CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString();
            $rows = (clone $open)->where('company_id', $company->id)->where('due_date', '<', $today)->groupBy('currency')->selectRaw('currency, sum(open_minor) as amount')->get();

            foreach ($rows as $row) {
                $overdue[$row->currency] = (string) ((int) ($overdue[$row->currency] ?? 0) + (int) $row->amount);
            }
        }

        ksort($overdue);

        return [
            'open' => (clone $open)->groupBy('currency')->orderBy('currency')->selectRaw('currency, sum(open_minor) as amount')->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'open_minor' => (string) $row->amount])->values()->all(),
            'overdue' => array_map(fn (string $currency, string $amount) => ['currency' => $currency, 'open_minor' => $amount], array_keys($overdue), array_values($overdue)),
            'recent' => $documents()->where('status', '<>', Document::CANCELLED)->orderByDesc('issue_date')->orderByDesc('created_at')->limit($limit)->get()
                ->map(fn (Document $document) => [
                    'id' => $document->id,
                    'number' => $document->number,
                    'kind' => $document->kind,
                    'issue_date' => $document->issue_date->toDateString(),
                    'due_date' => $document->due_date->toDateString(),
                    'currency' => $document->currency,
                    'total_minor' => (string) $document->total_minor,
                    'open_minor' => (string) $document->open_minor,
                    'status' => $document->status,
                ])->values()->all(),
        ];
    }
}
