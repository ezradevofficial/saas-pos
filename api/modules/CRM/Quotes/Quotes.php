<?php

namespace Modules\CRM\Quotes;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\CRM\Events\QuoteAccepted;
use Modules\CRM\Events\QuoteApproved;
use Modules\CRM\Events\QuoteConverted;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\QuoteLine;
use Modules\CRM\Models\Settings;
use Modules\CRM\Opportunities\Opportunities;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Records;
use Modules\CRM\Support\Tx;

/**
 * CRM-17 to CRM-24: quotes.
 *
 * - create() / update(): a draft for a customer at a branch, its currency,
 *   price list and tax treatment from the customer and the company
 *   (QuotePricing::listFor), lines priced and checked for the person
 *   saving (QuotePricing: prices, discounts, tax from core). A rejected
 *   quote edited becomes a draft again.
 * - submit(): the lines again, strict (a "Rate needed" code refuses), the
 *   base currency locked (CUR-02), the rate on the quote date frozen with
 *   the base amounts (CUR-04), `needs_approval` from the company's
 *   threshold, numbered (NUM-01) and the `crm.quote` flow started.
 * - completed() / cancelled(): the flow's outcome (WF-10, WF-11).
 * - markSent(), accept(), decline(), convert(), cancel().
 */
class Quotes
{
    public const NUMBER_TYPE = 'crm.quote';

    public function __construct(
        private readonly Records $records,
        private readonly QuotePricing $pricing,
        private readonly CompanySetup $setup,
        private readonly Numbering $numbering,
        private readonly WorkflowEngine $engine,
        private readonly BaseCurrencyLock $baseLock,
        private readonly Converter $converter,
        private readonly ModuleRegistry $modules,
        private readonly CrmAccess $access,
        private readonly Opportunities $opportunities,
    ) {}

    /**
     * CRM-17: the header a body gives (company, branch, customer,
     * currency, list, tax treatment, dates), for create and preview.
     *
     * @param  array<string, mixed>  $data
     * @return array{company: Company, branch: Branch, party_id: string, opportunity_id: ?string, currency: string, list: ?PriceList, inclusive: bool, quote_date: CarbonImmutable, valid_until: CarbonImmutable}
     */
    public function header(array $data, User $by, ?Quote $quote = null): array
    {
        $branch = $quote === null ? $this->records->branch((string) $data['branch_id']) : Branch::query()->with('company')->findOrFail($quote->branch_id);
        $company = $branch->company;
        $settings = $this->setup->ensure($company);
        $party = $this->records->customer($company->id, (string) ($data['party_id'] ?? $quote?->party_id));
        $opportunityId = array_key_exists('opportunity_id', $data) ? $data['opportunity_id'] : $quote?->opportunity_id;

        if ($opportunityId !== null) {
            $opportunity = Opportunity::query()->find($opportunityId);

            if ($opportunity === null || ! $this->access->sees($by, $opportunity, 'opportunity') || $opportunity->company_id !== $company->id) {
                throw CrmError::make('opportunity_unknown', field: 'opportunity_id');
            }

            if ($opportunity->party_id !== $party->id) {
                throw CrmError::make('opportunity_party_mismatch', field: 'opportunity_id');
            }
        }

        $currency = $this->records->currency((string) ($data['currency'] ?? $quote?->currency ?? $party->currency ?? $company->base_currency));
        $list = $this->pricing->listFor($company, $currency, $party->price_list_id);
        $zone = $company->timezone ?: 'UTC';
        $date = CarbonImmutable::parse((string) ($data['quote_date'] ?? $quote?->quote_date?->toDateString() ?? CarbonImmutable::now($zone)->toDateString()));
        $validUntil = CarbonImmutable::parse((string) ($data['valid_until'] ?? ($quote !== null && ! array_key_exists('quote_date', $data) ? $quote->valid_until->toDateString() : $date->addDays($settings->quote_validity_days)->toDateString())));

        if ($validUntil->lessThan($date)) {
            throw CrmError::make('valid_until_before_date', field: 'valid_until');
        }

        return [
            'company' => $company, 'branch' => $branch, 'party_id' => $party->id, 'opportunity_id' => $opportunityId, 'currency' => $currency, 'list' => $list,
            'inclusive' => $list?->tax_inclusive ?? (bool) ($data['tax_inclusive'] ?? $quote?->tax_inclusive ?? false), 'quote_date' => $date, 'valid_until' => $validUntil,
        ];
    }

    /**
     * CRM-18: a body priced without saving (the editor's live totals),
     * every problem collected.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(array $data, User $by): array
    {
        $header = $this->header($data, $by);
        $priced = $this->pricing->price($header['company'], $header['branch']->id, $header['currency'], $header['list'], $header['inclusive'], $header['quote_date'], $data['lines'] ?? [], $by, strict: false, collect: true);

        return [...$header, ...$priced];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Quote
    {
        return Tx::run(function () use ($data, $by) {
            $header = $this->header($data, $by);
            $quote = new Quote([
                'company_id' => $header['company']->id,
                'branch_id' => $header['branch']->id,
                'status' => Quote::DRAFT,
                'requested_by' => $by->id,
            ]);
            $this->applyHeader($quote, $header, $data);
            $owner = array_key_exists('owner_id', $data) ? $data['owner_id'] : ($this->records->eligible($by, $quote->branch_id, 'quote') ? $by->id : null);
            $quote->owner_id = $owner === null ? null : $this->records->owner($owner, $quote->branch_id, 'quote')->id;
            $quote->team_id = $this->team($quote, $data);
            $quote->save();
            $this->writeLines($quote, $header, $data['lines'], $by);

            return $quote->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Quote $quote, array $data, User $by): Quote
    {
        return Tx::run(function () use ($quote, $data, $by) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($quote->status, [Quote::DRAFT, Quote::REJECTED], true)) {
                throw CrmError::make('quote_not_draft', status: 409);
            }

            $header = $this->header($data, $by, $quote);
            $this->applyHeader($quote, $header, $data);

            if (array_key_exists('owner_id', $data)) {
                $quote->owner_id = $data['owner_id'] === null ? null : $this->records->owner($data['owner_id'], $quote->branch_id, 'quote')->id;
            }

            if (array_key_exists('team_id', $data) || array_key_exists('owner_id', $data)) {
                $quote->team_id = $this->team($quote, $data);
            }

            $quote->fill(['status' => Quote::DRAFT, 'decided_at' => null, 'decided_by' => null])->save();
            $lines = $data['lines'] ?? $quote->lines()->get()->map(fn (QuoteLine $line) => [
                'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $line->description,
                'qty' => (string) $line->qty, 'unit_price_minor' => (string) $line->unit_price_minor, 'discount_percent' => (string) $line->discount_percent, 'tax_code_id' => $line->tax_code_id,
            ])->all();
            // Lines priced again: from the body for the person saving, else the kept ones (a currency or date change) without their checks.
            QuoteLine::query()->where('quote_id', $quote->id)->delete();
            $this->writeLines($quote, $header, $lines, isset($data['lines']) ? $by : null);

            return $quote->refresh();
        });
    }

    public function submit(Quote $quote, User $by): Quote
    {
        return Tx::run(function () use ($quote, $by) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== Quote::DRAFT) {
                throw CrmError::make('quote_not_draft', status: 409);
            }

            $lines = QuoteLine::query()->where('quote_id', $quote->id)->orderBy('line_no')->get();

            if ($lines->isEmpty()) {
                throw CrmError::make('quote_empty', field: 'lines');
            }

            $company = Company::query()->findOrFail($quote->company_id);
            $this->records->customer($company->id, $quote->party_id);
            $base = $this->baseLock->lock($company);
            // MD-03: strict, so a "Rate needed" code refuses; the saved lines' prices and discounts were checked when saved.
            $priced = $this->pricing->price($company, $quote->branch_id, $quote->currency, $quote->price_list_id === null ? null : PriceList::query()->find($quote->price_list_id),
                $quote->tax_inclusive, $quote->quote_date, $lines->map(fn (QuoteLine $line) => $this->input($line))->all(), null, strict: true);
            $this->saveLines($quote, $priced['lines']);
            $fx = $this->fx($company, $quote->currency, $quote->quote_date);
            $baseAmounts = ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0];

            foreach ($priced['lines'] as $line) {
                foreach (['subtotal' => 'gross_minor', 'discount' => 'discount_minor', 'tax' => 'tax_minor', 'total' => 'total_minor'] as $key => $field) {
                    $baseAmounts[$key] += $fx->isIdentity() ? $line[$field] : (int) $fx->convert(Money::ofMinor((string) $line[$field], $quote->currency))->minor();
                }
            }

            $threshold = Settings::query()->where('company_id', $company->id)->value('quote_approval_minor');
            $quote->fill([
                'number' => $quote->number ?? $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, Branch::query()->find($quote->branch_id), at: $quote->quote_date->setTime(12, 0)))->number,
                'status' => Quote::PENDING,
                'submitted_at' => CarbonImmutable::now(),
                'fx' => $fx,
                'base_currency' => $base,
                'base_subtotal_minor' => $baseAmounts['subtotal'],
                'base_discount_minor' => $baseAmounts['discount'],
                'base_tax_minor' => $baseAmounts['tax'],
                'base_total_minor' => $baseAmounts['total'],
                'needs_approval' => $threshold !== null && $baseAmounts['total'] > (int) $threshold,
            ])->save();
            $this->engine->start(QuoteType::KEY, $quote->id, $by);

            return $quote->refresh();
        });
    }

    /** WF-10: the flow approved or rejected the quote. */
    public function completed(string $quoteId, string $outcome, ?string $userId): void
    {
        Tx::run(function () use ($quoteId, $outcome, $userId) {
            $quote = Quote::query()->whereKey($quoteId)->lockForUpdate()->first();

            if ($quote === null || $quote->status !== Quote::PENDING) {
                return;
            }

            $approved = $outcome === 'approved';
            $quote->fill(['status' => $approved ? Quote::APPROVED : Quote::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            if ($approved) {
                QuoteApproved::dispatch((string) $quote->tenant_id, $quote->id, $quote->company_id, $quote->branch_id, (string) $quote->number);
            }
        });
    }

    /** WF-11: the quote's flow was cancelled. */
    public function cancelled(string $quoteId): void
    {
        Tx::run(function () use ($quoteId) {
            $quote = Quote::query()->whereKey($quoteId)->lockForUpdate()->first();

            if ($quote !== null && $quote->status === Quote::PENDING) {
                $quote->fill(['status' => Quote::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Quote $quote, User $by, string $reason): Quote
    {
        return Tx::run(function () use ($quote, $by, $reason) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($quote->status, [Quote::DRAFT, Quote::PENDING, Quote::APPROVED, Quote::REJECTED, Quote::SENT], true)) {
                throw CrmError::make('quote_not_open', status: 409);
            }

            $workflow = $this->engine->current(QuoteType::KEY, $quote->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $quote->fill(['status' => Quote::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $quote->refresh();
        });
    }

    /** CRM-22: emailed or shared; an approved quote becomes `sent`. */
    public function markSent(Quote $quote): Quote
    {
        return Tx::run(function () use ($quote) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($quote->status, Quote::RELEASED, true)) {
                throw CrmError::make('quote_not_approved', status: 409);
            }

            $quote->fill(['sent_at' => CarbonImmutable::now(), 'status' => $quote->status === Quote::APPROVED ? Quote::SENT : $quote->status])->save();

            return $quote;
        });
    }

    /** CRM-23: the customer said yes (optionally winning the opportunity, `crm.opportunity.close`). */
    public function accept(Quote $quote, bool $winOpportunity, User $by): Quote
    {
        return Tx::run(function () use ($quote, $winOpportunity, $by) {
            $quote = $this->answerable($quote);
            $quote->fill(['status' => Quote::ACCEPTED, 'accepted_at' => CarbonImmutable::now(), 'answered_by' => $by->id])->save();

            if ($winOpportunity && $quote->opportunity_id !== null) {
                $opportunity = Opportunity::query()->findOrFail($quote->opportunity_id);

                if (! $this->access->may($by, 'crm.opportunity.close', $opportunity, 'opportunity')) {
                    throw new AuthorizationException(__('rbac.forbidden'));
                }

                if ($opportunity->status === Opportunity::OPEN) {
                    $this->opportunities->win($opportunity, ['note' => __('crm.quotes.won_by_quote', ['number' => $quote->number])], $by);
                }
            }

            QuoteAccepted::dispatch((string) $quote->tenant_id, $quote->id, $quote->company_id, $quote->branch_id, $quote->party_id, $quote->currency, (string) $quote->total_minor);

            return $quote->refresh();
        });
    }

    public function decline(Quote $quote, string $reason, User $by): Quote
    {
        return Tx::run(function () use ($quote, $reason, $by) {
            $quote = $this->answerable($quote, expiredAllowed: true);
            $quote->fill(['status' => Quote::DECLINED, 'declined_at' => CarbonImmutable::now(), 'answered_by' => $by->id, 'decline_reason' => $reason])->save();

            return $quote;
        });
    }

    /**
     * CRM-24: an accepted quote becomes a document of another module, in
     * this transaction (QuoteConverted). `invoice` needs Accounting active.
     */
    public function convert(Quote $quote, string $target, User $by): Quote
    {
        return Tx::run(function () use ($quote, $target, $by) {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== Quote::ACCEPTED) {
                throw CrmError::make($quote->status === Quote::CONVERTED ? 'quote_converted' : 'quote_not_accepted', status: 409);
            }

            if ($target !== 'invoice') {
                throw CrmError::make('target_unavailable', field: 'target', status: 409);
            }

            if (! $this->modules->isActive('accounting')) {
                throw CrmError::make('target_inactive', field: 'target', status: 409);
            }

            $company = Company::query()->findOrFail($quote->company_id);
            $lines = QuoteLine::query()->where('quote_id', $quote->id)->orderBy('line_no')->get();
            $event = new QuoteConverted(
                (string) $quote->tenant_id, $quote->id, (string) $quote->number, $target, $quote->company_id, $quote->branch_id, $quote->party_id,
                $quote->currency, $quote->tax_inclusive, $quote->quote_date->toDateString(), CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString(), $by->id,
                $lines->map(fn (QuoteLine $line) => [
                    'line_no' => $line->line_no, 'item_id' => $line->item_id, 'description' => $line->description, 'uom_id' => $line->uom_id, 'qty' => (string) $line->qty,
                    'unit_price_minor' => (string) $line->unit_price_minor, 'discount_percent' => (string) $line->discount_percent, 'gross_minor' => (string) $line->gross_minor,
                    'discount_minor' => (string) $line->discount_minor, 'amount_minor' => (string) ($line->gross_minor - $line->discount_minor), 'tax_code_id' => $line->tax_code_id,
                    'tax_minor' => (string) $line->tax_minor, 'total_minor' => (string) $line->total_minor,
                ])->all(),
                ['subtotal' => (string) $quote->subtotal_minor, 'discount' => (string) $quote->discount_minor, 'tax' => (string) $quote->tax_minor, 'total' => (string) $quote->total_minor],
            );
            event($event);

            if ($event->result === null) {
                throw CrmError::make('conversion_unhandled', field: 'target', status: 409);
            }

            $quote->fill(['status' => Quote::CONVERTED, 'converted_to' => $target, 'converted_ref' => $event->result, 'converted_at' => CarbonImmutable::now(), 'converted_by' => $by->id])->save();

            return $quote->refresh();
        });
    }

    private function answerable(Quote $quote, bool $expiredAllowed = false): Quote
    {
        $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

        if (! in_array($quote->status, [Quote::APPROVED, Quote::SENT], true)) {
            throw CrmError::make('quote_not_approved', status: 409);
        }

        $zone = Company::query()->whereKey($quote->company_id)->value('timezone') ?: 'UTC';

        if (! $expiredAllowed && $quote->isExpired($zone)) {
            throw CrmError::make('quote_expired', ['date' => $quote->valid_until->toDateString()], status: 409);
        }

        return $quote;
    }

    /** @param array<string, mixed> $header @param array<string, mixed> $data */
    private function applyHeader(Quote $quote, array $header, array $data): void
    {
        $quote->fill([
            'party_id' => $header['party_id'],
            'opportunity_id' => $header['opportunity_id'],
            'currency' => $header['currency'],
            'price_list_id' => $header['list']?->id,
            'tax_inclusive' => $header['inclusive'],
            'quote_date' => $header['quote_date']->toDateString(),
            'valid_until' => $header['valid_until']->toDateString(),
        ]);

        foreach (['terms', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $quote->{$key} = $data[$key];
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function team(Quote $quote, array $data): ?string
    {
        if (array_key_exists('team_id', $data)) {
            return $data['team_id'] === null ? null : $this->records->team($quote->company_id, $quote->branch_id, $data['team_id'])->id;
        }

        if ($quote->opportunity_id !== null && ($team = Opportunity::query()->whereKey($quote->opportunity_id)->value('team_id')) !== null) {
            return $team;
        }

        return $quote->owner_id === null ? null : $this->records->soleTeam($quote->company_id, $quote->branch_id, $quote->owner_id);
    }

    /** @param array<string, mixed> $header @param list<array<string, mixed>> $inputs */
    private function writeLines(Quote $quote, array $header, array $inputs, ?User $by): void
    {
        $priced = $this->pricing->price($header['company'], $quote->branch_id, $quote->currency, $header['list'], $quote->tax_inclusive, $quote->quote_date, $inputs, $by, strict: false);
        $this->saveLines($quote, $priced['lines']);
    }

    /** @param list<array<string, mixed>> $lines */
    private function saveLines(Quote $quote, array $lines): void
    {
        $existing = QuoteLine::query()->where('quote_id', $quote->id)->orderBy('line_no')->get()->keyBy('line_no');
        $totals = ['subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];
        $max = '0.00';

        foreach ($lines as $line) {
            $attributes = array_diff_key($line, ['rate_needed' => true]);
            $model = $existing->get($line['line_no']) ?? new QuoteLine(['quote_id' => $quote->id]);
            $model->fill($attributes)->save();

            foreach (['subtotal_minor' => 'gross_minor', 'discount_minor' => 'discount_minor', 'tax_minor' => 'tax_minor', 'total_minor' => 'total_minor'] as $total => $key) {
                $totals[$total] += $line[$key];
            }

            $max = BigDecimal::of($line['discount_percent'])->isGreaterThan($max) ? $line['discount_percent'] : $max;
        }

        $quote->fill([...$totals, 'max_discount_percent' => $max])->save();
    }

    /** @return array<string, mixed> a saved line as pricing input */
    private function input(QuoteLine $line): array
    {
        return [
            'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'description' => $line->description, 'qty' => (string) $line->qty,
            'unit_price_minor' => (string) $line->unit_price_minor, 'discount_percent' => (string) $line->discount_percent, 'tax_code_id' => $line->tax_code_id,
        ];
    }

    /** CUR-04: the company's rate for $currency → base at the end of $date (identity in the base currency). */
    private function fx(Company $company, string $currency, CarbonImmutable $date): FxSnapshot
    {
        if ($currency === $company->base_currency) {
            return FxSnapshot::identity($currency);
        }

        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, CarbonImmutable::parse($date->toDateString(), $company->timezone ?: 'UTC')->endOfDay())['snapshot'];
        } catch (RateUnavailable) {
            throw CrmError::make('rate_unavailable', ['pair' => "{$currency}/{$company->base_currency}", 'date' => $date->toDateString()], 'currency');
        }
    }
}
