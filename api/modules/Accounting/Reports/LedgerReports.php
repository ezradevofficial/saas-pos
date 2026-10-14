<?php

namespace Modules\Accounting\Reports;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Documents\Documents;
use Modules\Accounting\Ledger\Reversals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\BudgetLine;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Payments\Payments;

/**
 * ACC-11, ACC-16, ACC-17: the general ledger detail of an account (with
 * running balance, for drill-down), receivable and payable ageing, party
 * statements and budget against actual.
 */
class LedgerReports
{
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', 'over_90'];

    public const MAX_LINES = 2000;

    public function __construct(private readonly Statements $statements) {}

    /** GL detail: the account's posted lines in the range, oldest first, with the opening and a running balance in base currency. */
    public function generalLedger(Company $company, Account $account, string $from, string $to, array $dimensions = []): array
    {
        $opening = $this->statements->lines($company, null, CarbonImmutable::parse($from)->subDay()->toDateString(), $dimensions)
            ->where('acc_journal_lines.account_id', $account->id)
            ->selectRaw('coalesce(sum(acc_journal_lines.base_debit_minor - acc_journal_lines.base_credit_minor), 0) as balance')->value('balance');
        $rows = $this->statements->lines($company, $from, $to, $dimensions)
            ->where('acc_journal_lines.account_id', $account->id)
            ->leftJoin('parties', 'parties.id', '=', 'acc_journal_lines.party_id')
            ->orderBy('acc_journals.date')->orderBy('acc_journals.number')->orderBy('acc_journal_lines.line_no')
            ->limit(self::MAX_LINES + 1)
            ->get([
                'acc_journal_lines.id', 'acc_journals.id as journal_id', 'acc_journals.number', 'acc_journals.date', 'acc_journals.kind',
                'acc_journals.source_type', 'acc_journals.source_id', 'acc_journals.reference', 'acc_journals.memo',
                'acc_journal_lines.description', 'acc_journal_lines.currency', 'acc_journal_lines.debit_minor', 'acc_journal_lines.credit_minor',
                'acc_journal_lines.base_debit_minor', 'acc_journal_lines.base_credit_minor', 'acc_journal_lines.party_id', 'parties.name as party_name',
            ]);
        $balance = (int) $opening;
        $lines = [];
        $sources = $this->sourceNumbers($rows->take(self::MAX_LINES));

        foreach ($rows->take(self::MAX_LINES) as $row) {
            $balance += (int) $row->base_debit_minor - (int) $row->base_credit_minor;
            $lines[] = [
                'id' => $row->id,
                'journal' => ['id' => $row->journal_id, 'number' => $row->number, 'kind' => $row->kind, 'source_type' => $row->source_type, 'source_id' => $row->source_id,
                    'source_number' => $sources[$row->source_type.':'.$row->source_id] ?? null],
                'date' => $row->date, 'reference' => $row->reference, 'description' => $row->description ?? $row->memo,
                'party' => $row->party_id === null ? null : ['id' => $row->party_id, 'name' => $row->party_name],
                'currency' => $row->currency, 'debit' => (int) $row->debit_minor, 'credit' => (int) $row->credit_minor,
                'base_debit' => (int) $row->base_debit_minor, 'base_credit' => (int) $row->base_credit_minor, 'balance' => $balance,
            ];
        }

        return [
            'currency' => $company->base_currency, 'from' => $from, 'to' => $to,
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'opening' => (int) $opening, 'closing' => $balance, 'lines' => $lines, 'truncated' => $rows->count() > self::MAX_LINES,
        ];
    }

    /**
     * ACC-17: the number of each line's source (the invoice, payment or
     * reversed journal), from this module's own tables, keyed `type:id`.
     *
     * @param  iterable<object>  $rows
     * @return array<string, string>
     */
    private function sourceNumbers(iterable $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row->source_type !== null && $row->source_id !== null) {
                $ids[$row->source_type][] = $row->source_id;
            }
        }

        $numbers = [];

        foreach ([Documents::SOURCE => 'acc_documents', Payments::SOURCE => 'acc_payments', Reversals::SOURCE => 'acc_journals'] as $type => $table) {
            if (($ids[$type] ?? []) === []) {
                continue;
            }

            foreach (DB::connection(TenantContext::CONNECTION)->table($table)->whereIn('id', array_values(array_unique($ids[$type])))->pluck('number', 'id') as $id => $number) {
                if ($number !== null) {
                    $numbers[$type.':'.$id] = (string) $number;
                }
            }
        }

        return $numbers;
    }

    /**
     * Ageing at $asOf of the receivable (customers) or payable (suppliers):
     * per party, what is open by days past due, in each document currency,
     * and in base at the documents' rates. Credit notes and unallocated
     * receipts or payments count against the party (negative, current).
     */
    public function ageing(Company $company, string $side, string $asOf, ?string $partyId = null): array
    {
        $kinds = $side === 'customer' ? ['customer_invoice', 'customer_credit'] : ['supplier_invoice', 'supplier_credit'];
        $documents = Document::query()->where('company_id', $company->id)->where('status', Document::POSTED)->whereIn('kind', $kinds)
            ->where('issue_date', '<=', $asOf)->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))->with('party:id,name')->get();
        $payments = Payment::query()->where('company_id', $company->id)->where('kind', $side === 'customer' ? 'receipt' : 'payment')
            ->where('date', '<=', $asOf)->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))->with('party:id,name')->get();
        $settledInvoices = Allocation::query()->whereIn('document_id', $documents->pluck('id'))->where('date', '<=', $asOf)->groupBy('document_id')->selectRaw('document_id, sum(amount_minor) as amount')->pluck('amount', 'document_id');
        $settledCredits = Allocation::query()->whereIn('credit_document_id', $documents->pluck('id'))->where('date', '<=', $asOf)->groupBy('credit_document_id')->selectRaw('credit_document_id, sum(amount_minor) as amount')->pluck('amount', 'credit_document_id');
        $settledPayments = Allocation::query()->whereIn('payment_id', $payments->pluck('id'))->where('date', '<=', $asOf)->groupBy('payment_id')->selectRaw('payment_id, sum(amount_minor) as amount')->pluck('amount', 'payment_id');
        $date = CarbonImmutable::parse($asOf);
        $parties = [];

        $add = function (string $partyId, string $partyName, string $currency, string $bucket, int $amount, int $base) use (&$parties) {
            $key = $partyId.'|'.$currency;
            $parties[$key] ??= ['party' => ['id' => $partyId, 'name' => $partyName], 'currency' => $currency, 'buckets' => array_fill_keys(self::BUCKETS, 0), 'total' => 0, 'base_total' => 0];
            $parties[$key]['buckets'][$bucket] += $amount;
            $parties[$key]['total'] += $amount;
            $parties[$key]['base_total'] += $base;
        };

        foreach ($documents as $document) {
            $settled = (int) ($document->isCredit() ? ($settledCredits[$document->id] ?? 0) : ($settledInvoices[$document->id] ?? 0));
            $open = $document->total_minor - $settled;

            if ($open <= 0) {
                continue;
            }

            $sign = $document->isCredit() ? -1 : 1;
            $days = (int) $document->due_date->diffInDays($date, false);
            $add($document->party_id, (string) $document->party?->name, $document->currency, $document->isCredit() ? 'current' : self::bucket($days), $sign * $open, $sign * $this->base($document->base_total_minor, $document->total_minor, $open));
        }

        foreach ($payments as $payment) {
            $open = $payment->amount_minor - (int) ($settledPayments[$payment->id] ?? 0);

            if ($open > 0) {
                $add($payment->party_id, (string) $payment->party?->name, $payment->currency, 'current', -$open, -$this->base($payment->base_amount_minor, $payment->amount_minor, $open));
            }
        }

        $rows = array_values(array_filter($parties, fn (array $row) => $row['total'] !== 0));
        usort($rows, fn (array $a, array $b) => [$a['party']['name'], $a['currency']] <=> [$b['party']['name'], $b['currency']]);

        return ['side' => $side, 'as_of' => $asOf, 'base_currency' => $company->base_currency, 'rows' => $rows, 'base_total' => array_sum(array_column($rows, 'base_total'))];
    }

    /** A party's statement in one currency: opening, documents and receipts or payments in the range with a running balance. */
    public function partyStatement(Company $company, string $partyId, string $side, string $currency, string $from, string $to): array
    {
        $kinds = $side === 'customer' ? ['customer_invoice', 'customer_credit'] : ['supplier_invoice', 'supplier_credit'];
        $entries = [];
        $opening = 0;

        foreach (Document::query()->where('company_id', $company->id)->where('party_id', $partyId)->where('status', Document::POSTED)
            ->whereIn('kind', $kinds)->where('currency', $currency)->where('issue_date', '<=', $to)->get() as $document) {
            $amount = $document->isCredit() ? -$document->total_minor : $document->total_minor;
            $date = $document->issue_date->toDateString();
            $date < $from ? $opening += $amount : $entries[] = ['date' => $date, 'type' => $document->kind, 'id' => $document->id, 'number' => $document->number, 'due_date' => $document->due_date->toDateString(), 'amount' => $amount];
        }

        foreach (Payment::query()->where('company_id', $company->id)->where('party_id', $partyId)->where('kind', $side === 'customer' ? 'receipt' : 'payment')
            ->where('currency', $currency)->where('date', '<=', $to)->get() as $payment) {
            $date = $payment->date->toDateString();
            $date < $from ? $opening -= $payment->amount_minor : $entries[] = ['date' => $date, 'type' => $payment->kind, 'id' => $payment->id, 'number' => $payment->number, 'due_date' => null, 'amount' => -$payment->amount_minor];
        }

        usort($entries, fn (array $a, array $b) => [$a['date'], $a['number']] <=> [$b['date'], $b['number']]);
        $balance = $opening;

        foreach ($entries as &$entry) {
            $balance += $entry['amount'];
            $entry['balance'] = $balance;
        }

        return ['currency' => $currency, 'side' => $side, 'from' => $from, 'to' => $to, 'opening' => $opening, 'closing' => $balance, 'entries' => $entries];
    }

    /** Budget against actual (base currency) per account and cost centre, for the budget's months inside the range. */
    public function budgetVsActual(Company $company, Budget $budget, string $from, string $to): array
    {
        $periods = DB::table('acc_periods')->where('company_id', $company->id)->where('fiscal_year', $budget->fiscal_year)
            ->where('ends_on', '>=', $from)->where('starts_on', '<=', $to)->pluck('number')->all();
        $budgeted = BudgetLine::query()->where('budget_id', $budget->id)->whereIn('month', $periods)
            ->groupBy('account_id', 'cost_centre_id')->selectRaw('account_id, cost_centre_id, sum(amount_minor) as amount')->get();
        $actuals = $this->statements->lines($company, $from, $to)->where('acc_journals.kind', '<>', 'closing')
            ->groupBy('acc_journal_lines.account_id', 'acc_journal_lines.cost_centre_id')
            ->selectRaw('acc_journal_lines.account_id, acc_journal_lines.cost_centre_id, sum(acc_journal_lines.base_debit_minor - acc_journal_lines.base_credit_minor) as amount')
            ->get();
        $accounts = Account::query()->where('company_id', $company->id)->get()->keyBy('id');
        $centres = DB::table('cost_centres')->where('company_id', $company->id)->pluck('name', 'id');
        $rows = [];

        foreach ($budgeted as $row) {
            $rows[$row->account_id.'|'.$row->cost_centre_id] = ['account_id' => $row->account_id, 'cost_centre_id' => $row->cost_centre_id, 'budget' => (int) $row->amount, 'actual' => 0];
        }

        foreach ($actuals as $row) {
            $key = $row->account_id.'|'.$row->cost_centre_id;
            $account = $accounts[$row->account_id] ?? null;

            // Only accounts the budget covers, or income and expense accounts (profit and loss).
            if (! isset($rows[$key]) && ($account === null || $account->isBalanceSheet())) {
                continue;
            }

            $rows[$key] ??= ['account_id' => $row->account_id, 'cost_centre_id' => $row->cost_centre_id, 'budget' => 0, 'actual' => 0];
            // Income budgets and actuals are credits: shown positive like expenses.
            $rows[$key]['actual'] += $account !== null && $account->type === 'income' ? -(int) $row->amount : (int) $row->amount;
        }

        $out = [];

        foreach ($rows as $row) {
            $account = $accounts[$row['account_id']] ?? null;
            $out[] = [
                'account' => $account === null ? null : ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
                'cost_centre' => $row['cost_centre_id'] === null ? null : ['id' => $row['cost_centre_id'], 'name' => $centres[$row['cost_centre_id']] ?? null],
                'budget' => $row['budget'], 'actual' => $row['actual'], 'variance' => $row['budget'] - $row['actual'],
            ];
        }

        usort($out, fn (array $a, array $b) => [$a['account']['code'] ?? '', $a['cost_centre']['name'] ?? ''] <=> [$b['account']['code'] ?? '', $b['cost_centre']['name'] ?? '']);

        return ['currency' => $company->base_currency, 'from' => $from, 'to' => $to, 'budget' => ['id' => $budget->id, 'name' => $budget->name, 'fiscal_year' => $budget->fiscal_year], 'rows' => $out];
    }

    public static function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current',
            $daysPastDue <= 30 => '1_30',
            $daysPastDue <= 60 => '31_60',
            $daysPastDue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    /** The base value of $open out of a document's total, at the document's own rate (rounded half up). */
    private function base(int $baseTotal, int $total, int $open): int
    {
        if ($total === 0) {
            return 0;
        }

        return (int) (string) BigDecimal::of($baseTotal)->multipliedBy($open)->dividedBy($total, 0, RoundingMode::HalfUp);
    }
}
