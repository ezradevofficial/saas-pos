<?php

namespace Modules\Accounting\Payments;

use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Payment;

/**
 * ACC-10: settles an invoice with a receipt, a payment or a credit note.
 *
 * Both are posted, of the same company, party, side and currency
 * (decision 4); the amount is at most what each still has open. The
 * invoice's control account was booked at the invoice's rate, the receipt
 * at its own: the base-currency residue of the settled part (invoice base
 * − source base) is the realised FX difference, posted against the
 * control account (with the party) to the FX gain or loss account. Each
 * allocation is a record of its own; nothing is ever deleted. It is dated
 * on or after both documents, in an open period, and its realised FX is
 * dated the same day.
 */
class Allocations
{
    public const SOURCE = 'accounting.allocation';

    /** source kind => the invoice kind it settles */
    private const SETTLES = [
        'receipt' => 'customer_invoice',
        'payment' => 'supplier_invoice',
        'customer_credit' => 'customer_invoice',
        'supplier_credit' => 'supplier_invoice',
    ];

    public function __construct(
        private readonly JournalPoster $poster,
        private readonly Charts $charts,
        private readonly Periods $periods,
    ) {}

    public function allocate(Payment|Document $source, Document $invoice, Money $amount, CarbonImmutable $date, User $by): Allocation
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($source, $invoice, $amount, $date, $by) {
            $source = $source::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $invoice = Document::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $sourceKind = $source instanceof Payment ? $source->kind : $source->kind;
            $open = $source instanceof Payment ? $source->unallocated_minor : $source->open_minor;

            if ((self::SETTLES[$sourceKind] ?? null) !== $invoice->kind || $invoice->status !== Document::POSTED
                || ($source instanceof Document && $source->status !== Document::POSTED)
                || $invoice->company_id !== $source->company_id || $invoice->party_id !== $source->party_id) {
                throw LedgerError::make('allocation_mismatch', field: 'document_id');
            }

            if ($invoice->currency !== $source->currency || $amount->currency() !== $invoice->currency) {
                throw LedgerError::make('allocation_currency', ['currency' => $invoice->currency], 'document_id');
            }

            $minor = (int) $amount->minor();

            if ($minor <= 0 || $minor > $invoice->open_minor || $minor > $open) {
                throw LedgerError::make('allocation_too_much', ['open' => (string) min($invoice->open_minor, $open)], 'amount');
            }

            $company = Company::query()->findOrFail($invoice->company_id);
            $sourceDate = $source instanceof Payment ? $source->date : $source->issue_date;

            // A settlement is dated on or after both documents, in an open period.
            if ($date->toDateString() < $invoice->issue_date->toDateString() || $date->toDateString() < $sourceDate->toDateString()) {
                throw LedgerError::make('allocation_date', ['date' => max($invoice->issue_date, $sourceDate)->toDateString()], 'date');
            }

            if ($this->periods->forDate($company, $date)->isClosed()) {
                throw LedgerError::make('period_closed', ['date' => $date->toDateString()], 'date');
            }

            $atInvoice = $this->toBase($invoice->fx, $amount);
            $atSource = $this->toBase($source->fx, $amount);

            $allocation = Allocation::query()->create([
                'company_id' => $company->id,
                'payment_id' => $source instanceof Payment ? $source->id : null,
                'credit_document_id' => $source instanceof Document ? $source->id : null,
                'document_id' => $invoice->id,
                'date' => $date->toDateString(),
                'currency' => $invoice->currency,
                'amount_minor' => $minor,
                'base_at_document_minor' => $atInvoice,
                'base_at_source_minor' => $atSource,
                'fx_difference_minor' => $atSource - $atInvoice,
                'created_by' => $by->id,
            ]);

            if ($atSource !== $atInvoice) {
                $allocation->forceFill(['journal_id' => $this->postDifference($company, $invoice, $allocation, $date, $by)])->save();
            }

            $invoice->fill(['open_minor' => $invoice->open_minor - $minor])->save();
            $source->fill($source instanceof Payment ? ['unallocated_minor' => $open - $minor] : ['open_minor' => $open - $minor])->save();

            return $allocation;
        });
    }

    /**
     * The control account's base residue of the settled part: for a
     * receivable, booked (invoice) minus received (source); a positive
     * residue is a loss (less base came in), a negative one a gain. For a
     * payable the signs flip (paying less base is a gain).
     */
    private function postDifference(Company $company, Document $invoice, Allocation $allocation, CarbonImmutable $date, User $by): string
    {
        $base = (string) $company->base_currency;
        $customer = $invoice->side() === 'customer';
        $residue = $allocation->base_at_document_minor - $allocation->base_at_source_minor;
        $gain = $customer ? $residue < 0 : $residue > 0;
        $control = $this->charts->accountFor($company->id, $customer ? 'receivable' : 'payable');
        $fxAccount = $this->charts->accountFor($company->id, $gain ? 'fx_gain' : 'fx_loss');
        $value = Money::ofMinor((string) abs($residue), $base);
        // The control line clears the residue: a receivable residue is cleared by a credit, a payable one by a debit.
        $controlSide = ($customer xor $residue < 0) ? LineInput::CREDIT : LineInput::DEBIT;

        return $this->poster->record($company, [
            'date' => $date,
            'kind' => 'system',
            'source_type' => self::SOURCE,
            'source_id' => $allocation->id,
            'reference' => $invoice->number,
            'memo' => __('accounting.payments.fx_memo', ['number' => (string) $invoice->number]),
            'created_by' => $by->id,
        ], [
            new LineInput($control, $controlSide, Money::ofMinor(0, $invoice->currency), $invoice->fx, $value, null, ['party_id' => $invoice->party_id]),
            new LineInput($fxAccount, $controlSide === LineInput::CREDIT ? LineInput::DEBIT : LineInput::CREDIT, $value, FxSnapshot::identity($base), null, __('accounting.payments.fx_line')),
        ], $by)->id;
    }

    private function toBase(?FxSnapshot $fx, Money $amount): int
    {
        return $fx === null || $fx->isIdentity() ? (int) $amount->minor() : (int) $fx->convert($amount)->minor();
    }
}
