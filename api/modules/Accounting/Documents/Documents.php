<?php

namespace Modules\Accounting\Documents;

use App\Core\Audit\Auditor;
use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Converter;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Events\DocumentPosted;
use Modules\Accounting\Ledger\DimensionCheck;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Payments\Allocations;

/**
 * ACC-09: customer and supplier invoices and credit notes.
 *
 * - Drafts are saved with their amounts computed: line amount =
 *   round_half_up(qty × unit price); tax per line from the core tax code
 *   and the rate in force on the issue date in the company's time zone
 *   (TaxCalculator, MD-03), inclusive or exclusive per document. A line
 *   whose code has no confirmed rate is saved without tax and flagged
 *   `rate_needed`; posting it is refused (`tax_rate_missing`), as on the till.
 * - Posting recomputes the amounts, converts each line to base currency at
 *   the document's rate (CUR-04: the sum of rounded lines, never a
 *   recomputed total), numbers it (NUM-01) and posts its journal: the
 *   control account (with the party) against the lines' accounts and the
 *   tax account. A credit note naming an invoice settles it up to the
 *   invoice's open amount and never credits more than its total.
 */
class Documents
{
    /** kind => number type, default pattern */
    public const NUMBERING = [
        'customer_invoice' => ['accounting.customer_invoice', 'INV-{YYYY}-{00001}'],
        'customer_credit' => ['accounting.customer_credit', 'CN-{YYYY}-{00001}'],
        'supplier_invoice' => ['accounting.supplier_invoice', 'BILL-{YYYY}-{00001}'],
        'supplier_credit' => ['accounting.supplier_credit', 'DN-{YYYY}-{00001}'],
    ];

    public const SOURCE = 'accounting.document';

    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly CurrencyDecimals $decimals,
        private readonly Converter $converter,
        private readonly BaseCurrencyLock $baseLock,
        private readonly JournalPoster $poster,
        private readonly Numbering $numbering,
        private readonly Charts $charts,
        private readonly LineParser $parser,
        private readonly Allocations $allocations,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data the validated request */
    public function create(Company $company, array $data, User $by): Document
    {
        return $this->transaction(function () use ($company, $data, $by) {
            $party = $this->party($company, $data['kind'], $data['party_id']);
            $currency = $data['currency'] ?? ($party->currency ?: $company->base_currency);
            $issue = CarbonImmutable::parse($data['issue_date']);

            $document = Document::query()->create([
                'company_id' => $company->id,
                'branch_id' => $this->branch($company, $data['branch_id'] ?? null),
                'kind' => $data['kind'],
                'party_id' => $party->id,
                'issue_date' => $issue->toDateString(),
                'due_date' => $data['due_date'] ?? $issue->addDays((int) ($party->payment_terms_days ?? 0))->toDateString(),
                'currency' => $currency,
                'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
                'fx' => $this->manualRate($company, $currency, $data['rate'] ?? null),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'credits_document_id' => $this->credited($company, $data['kind'], $party, $currency, $data['credits_document_id'] ?? null),
                'memo' => $data['memo'] ?? null,
                'status' => Document::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeLines($company, $document, $data['lines']);

            return $document->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Document $document, array $data): Document
    {
        return $this->transaction(function () use ($document, $data) {
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== Document::DRAFT) {
                throw LedgerError::make('document_not_draft');
            }

            $company = Company::query()->findOrFail($document->company_id);
            $party = $this->party($company, $document->kind, $data['party_id'] ?? $document->party_id);
            $currency = $data['currency'] ?? $document->currency;
            $document->fill([
                'party_id' => $party->id,
                'branch_id' => array_key_exists('branch_id', $data) ? $this->branch($company, $data['branch_id']) : $document->branch_id,
                'issue_date' => $data['issue_date'] ?? $document->issue_date->toDateString(),
                'due_date' => $data['due_date'] ?? $document->due_date->toDateString(),
                'currency' => $currency,
                'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? $document->tax_inclusive),
                'fx' => array_key_exists('rate', $data) ? $this->manualRate($company, $currency, $data['rate']) : ($currency === $document->currency ? $document->fx : null),
                'supplier_reference' => array_key_exists('supplier_reference', $data) ? $data['supplier_reference'] : $document->supplier_reference,
                'credits_document_id' => array_key_exists('credits_document_id', $data)
                    ? $this->credited($company, $document->kind, $party, $currency, $data['credits_document_id'])
                    : $document->credits_document_id,
                'memo' => array_key_exists('memo', $data) ? $data['memo'] : $document->memo,
            ])->save();

            if (isset($data['lines'])) {
                DocumentLine::query()->where('document_id', $document->id)->delete();
                $this->writeLines($company, $document, $data['lines']);
            }

            return $document->refresh();
        });
    }

    public function cancel(Document $document): Document
    {
        return $this->transaction(function () use ($document) {
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== Document::DRAFT) {
                throw LedgerError::make('document_not_draft');
            }

            $document->fill(['status' => Document::CANCELLED])->save();

            return $document;
        });
    }

    public function post(Document $document, User $by): Document
    {
        return $this->transaction(function () use ($document, $by) {
            $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($document->status !== Document::DRAFT) {
                throw LedgerError::make('document_not_draft');
            }

            $company = Company::query()->findOrFail($document->company_id);
            $base = $this->baseLock->lock($company);
            $this->party($company, $document->kind, $document->party_id);
            $lines = DocumentLine::query()->where('document_id', $document->id)->orderBy('line_no')->get();

            if ($lines->isEmpty()) {
                throw LedgerError::make('document_empty', field: 'lines');
            }

            // MD-03: the rates in force on the issue date; a "Rate needed" code refuses (tax_rate_missing).
            foreach ($lines as $line) {
                $this->compute($company, $document, $line, strict: true);
                $line->save();
            }

            $fx = $this->rateFor($company, $document, $base);
            $baseNet = 0;
            $baseTax = 0;
            $journalLines = [];
            $side = $this->sides($document);

            foreach ($lines as $line) {
                $lineNet = $fx->isIdentity() ? $line->net_minor : (int) $fx->convert(Money::ofMinor($line->net_minor, $document->currency))->minor();
                $lineTax = $fx->isIdentity() ? $line->tax_minor : (int) $fx->convert(Money::ofMinor($line->tax_minor, $document->currency))->minor();
                $baseNet += $lineNet;
                $baseTax += $lineTax;
                $journalLines[] = new LineInput($line->account_id, $side['lines'], Money::ofMinor($line->net_minor, $document->currency), $fx, Money::ofMinor($lineNet, $base), $line->description, [
                    'branch_id' => $document->branch_id, 'cost_centre_id' => $line->cost_centre_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id,
                ]);
            }

            $subtotal = (int) $lines->sum('net_minor');
            $tax = (int) $lines->sum('tax_minor');
            $total = (int) $lines->sum('total_minor');

            if ($tax > 0 || $baseTax > 0) {
                $journalLines[] = new LineInput($this->charts->accountFor($company->id, $document->side() === 'customer' ? 'sales_tax' : 'purchase_tax'), $side['lines'],
                    Money::ofMinor($tax, $document->currency), $fx, Money::ofMinor($baseTax, $base), __('accounting.documents.tax_line'), ['branch_id' => $document->branch_id]);
            }

            $journalLines[] = new LineInput($this->charts->accountFor($company->id, $document->side() === 'customer' ? 'receivable' : 'payable'), $side['control'],
                Money::ofMinor($total, $document->currency), $fx, Money::ofMinor($baseNet + $baseTax, $base), null, ['party_id' => $document->party_id, 'branch_id' => $document->branch_id]);

            $this->assertCreditWithinInvoice($document, $total);
            [$type] = self::NUMBERING[$document->kind];
            $number = $this->numbering->next($type, new NumberContext($company, $document->branch_id === null ? null : Branch::query()->find($document->branch_id), at: $document->issue_date->setTime(12, 0)));

            $journal = $this->poster->record($company, [
                'date' => $document->issue_date,
                'kind' => 'system',
                'source_type' => self::SOURCE,
                'source_id' => $document->id,
                'reference' => $number->number,
                'memo' => $document->memo,
                'created_by' => $by->id,
            ], $journalLines, $by);

            $document->fill([
                'status' => Document::POSTED,
                'number' => $number->number,
                'journal_id' => $journal->id,
                'posted_at' => CarbonImmutable::now(),
                'posted_by' => $by->id,
                'fx' => $fx,
                'base_currency' => $base,
                'subtotal_minor' => $subtotal,
                'tax_minor' => $tax,
                'total_minor' => $total,
                'base_subtotal_minor' => $baseNet,
                'base_tax_minor' => $baseTax,
                'base_total_minor' => $baseNet + $baseTax,
                'open_minor' => $total,
            ])->save();
            $this->auditor->record('accounting.document.post', $document, null, ['number' => $number->number, 'journal' => $journal->id]);

            // A credit note naming an invoice settles it as far as both are open.
            if ($document->credits_document_id !== null) {
                $invoice = Document::query()->whereKey($document->credits_document_id)->lockForUpdate()->firstOrFail();
                $amount = min($invoice->open_minor, $total);

                if ($amount > 0) {
                    $this->allocations->allocate($document, $invoice, Money::ofMinor($amount, $document->currency), $document->issue_date, $by);
                }
            }

            DocumentPosted::dispatch((string) $document->tenant_id, $document->id, $document->company_id, $document->kind);

            return $document->refresh();
        });
    }

    /** Whether a draft line's code still needs a rate (shown as "Rate needed"). */
    public static function rateNeeded(DocumentLine $line): bool
    {
        return $line->tax_code_id !== null && $line->tax_rate === null && ! TaxCode::query()->whereKey($line->tax_code_id)->where('kind', 'exempt')->exists();
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Company $company, Document $document, array $lines): void
    {
        $accounts = Account::query()->where('company_id', $company->id)->whereKey(array_column($lines, 'account_id'))->get()->keyBy('id');

        foreach (array_values($lines) as $index => $input) {
            $account = $accounts->get($input['account_id']);

            if ($account === null || ! $account->postable || $account->isArchived() || $account->is_control) {
                throw LedgerError::make('document_account', field: "lines.{$index}.account_id");
            }

            if (($input['tax_code_id'] ?? null) !== null && ! TaxCode::query()->where('company_id', $company->id)->whereKey($input['tax_code_id'])->exists()) {
                throw LedgerError::make('tax_code_unknown', field: "lines.{$index}.tax_code_id");
            }

            $line = new DocumentLine([
                'document_id' => $document->id,
                'line_no' => $index + 1,
                'description' => $input['description'],
                'item_id' => $input['item_id'] ?? null,
                'account_id' => $account->id,
                'qty' => (string) BigDecimal::of((string) $input['qty'])->toScale(4, RoundingMode::HalfUp),
                'unit_price_minor' => (int) Money::parse((string) $input['unit_price'], $document->currency, $this->decimals)->minor(),
                'tax_code_id' => $input['tax_code_id'] ?? null,
                'cost_centre_id' => $input['cost_centre_id'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'project_id' => $input['project_id'] ?? null,
            ]);
            $this->compute($company, $document, $line, strict: false);
            $line->save();
        }

        DimensionCheck::assert($company, array_map(fn (array $input) => array_intersect_key($input, array_flip(['cost_centre_id', 'department_id', 'project_id'])), array_values($lines)));
        $this->refreshTotals($document);
    }

    /** Line amounts from qty, price and tax; strict refuses a code without a confirmed rate. */
    private function compute(Company $company, Document $document, DocumentLine $line, bool $strict): void
    {
        $amount = Money::ofMinor((string) BigDecimal::of((string) $line->qty)->multipliedBy($line->unit_price_minor)->toScale(0, RoundingMode::HalfUp), $document->currency);
        $codes = $line->tax_code_id === null ? [] : [TaxCode::query()->findOrFail($line->tax_code_id)];
        $at = CarbonImmutable::parse($document->issue_date->toDateString(), $company->timezone ?: 'UTC')->setTime(12, 0);

        try {
            $result = $this->tax->forLine($amount, $codes, (bool) $document->tax_inclusive, $at);
            $rate = $result->tax[0]->rate ?? null;
            $line->fill(['net_minor' => (int) $result->net->minor(), 'tax_minor' => (int) $result->totalTax()->minor(), 'total_minor' => (int) $result->gross->minor(), 'tax_rate' => $rate]);
        } catch (TaxRateMissing $e) {
            if ($strict) {
                throw $e;
            }

            $line->fill(['net_minor' => (int) $amount->minor(), 'tax_minor' => 0, 'total_minor' => (int) $amount->minor(), 'tax_rate' => null]);
        }
    }

    private function refreshTotals(Document $document): void
    {
        $lines = DocumentLine::query()->where('document_id', $document->id)->get();
        $document->fill([
            'subtotal_minor' => (int) $lines->sum('net_minor'),
            'tax_minor' => (int) $lines->sum('tax_minor'),
            'total_minor' => (int) $lines->sum('total_minor'),
        ])->save();
    }

    /** @return array{lines: string, control: string} which side the lines and the control account take */
    private function sides(Document $document): array
    {
        $debitLines = in_array($document->kind, ['supplier_invoice', 'customer_credit'], true);

        return ['lines' => $debitLines ? LineInput::DEBIT : LineInput::CREDIT, 'control' => $debitLines ? LineInput::CREDIT : LineInput::DEBIT];
    }

    private function rateFor(Company $company, Document $document, string $base): FxSnapshot
    {
        if ($document->currency === $base) {
            return FxSnapshot::identity($base);
        }

        if ($document->fx !== null && ! $document->fx->isIdentity()) {
            return $document->fx;
        }

        try {
            return $this->converter->toBase(Money::ofMinor(0, $document->currency), $company, CarbonImmutable::parse($document->issue_date->toDateString(), $company->timezone ?: 'UTC')->endOfDay())['snapshot'];
        } catch (RateUnavailable) {
            throw LedgerError::make('rate_unavailable', ['pair' => "{$document->currency}/{$base}", 'date' => $document->issue_date->toDateString()], 'currency');
        }
    }

    private function manualRate(Company $company, string $currency, mixed $rate): ?FxSnapshot
    {
        if ($currency !== $company->base_currency) {
            $this->parser->assertPro('currency');
        }

        if ($rate === null || $rate === '' || $currency === $company->base_currency) {
            return null;
        }

        return new FxSnapshot(Rate::normalise((string) $rate), $currency, (string) $company->base_currency, 'manual', null);
    }

    /** The party, if it has the role the document needs and is the company's or shared. */
    private function party(Company $company, string $kind, string $partyId): Party
    {
        $role = str_starts_with($kind, 'customer') ? 'customer' : 'supplier';
        $party = Party::query()->whereKey($partyId)->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->first();

        if ($party === null || $party->isArchived() || ! in_array($role, $party->roles ?? [], true)) {
            throw LedgerError::make("party_not_{$role}", field: 'party_id');
        }

        return $party;
    }

    private function branch(Company $company, ?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return Branch::query()->where('company_id', $company->id)->whereKey($branchId)->value('id') ?? throw LedgerError::make('branch_unknown', field: 'branch_id');
    }

    /** A credit note names a posted invoice of the same side, party and currency. */
    private function credited(Company $company, string $kind, Party $party, string $currency, ?string $invoiceId): ?string
    {
        if ($invoiceId === null) {
            return null;
        }

        $invoiceKind = str_replace('_credit', '_invoice', $kind);
        $invoice = Document::query()->where('company_id', $company->id)->whereKey($invoiceId)->first();

        if (! str_ends_with($kind, '_credit') || $invoice === null || $invoice->kind !== $invoiceKind || $invoice->status !== Document::POSTED
            || $invoice->party_id !== $party->id || $invoice->currency !== $currency) {
            throw LedgerError::make('credit_invoice_invalid', field: 'credits_document_id');
        }

        return $invoice->id;
    }

    /** A credit note never credits more than its invoice's total (with the credit notes posted before). */
    private function assertCreditWithinInvoice(Document $credit, int $total): void
    {
        if ($credit->credits_document_id === null) {
            return;
        }

        $invoice = Document::query()->findOrFail($credit->credits_document_id);
        $credited = (int) Document::query()->where('credits_document_id', $invoice->id)->where('status', Document::POSTED)->sum('total_minor');

        if ($credited + $total > $invoice->total_minor) {
            throw LedgerError::make('credit_exceeds_invoice', ['number' => (string) $invoice->number], 'lines');
        }
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
