<?php

namespace App\Core\Billing\Invoicing;

use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Overage\OverageLine;
use App\Core\Fiscal\FiscalQueue;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Platform\PlatformDatabase;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SUB-03: invoices of the platform, issued by the operator.
 *
 * issue() runs in the customer's context. In one database transaction it:
 * 1. enters the operator's context and creates the invoice there, as a
 *    document of the operator's selling company in the subscription's
 *    country: numbered (`core.billing_invoice`, NUM-01), taxed at that
 *    company's rate for its billing tax code (prices are tax-inclusive),
 *    and handed to the fiscal queue (eTIMS, DGI) as source `billing`;
 * 2. back in the customer's context, writes the customer's copy
 *    (BillingInvoice), the read model its screens use.
 *
 * Lines: one per quote line (amounts over the months of the cycle), the
 * annual discount and the coupon as negative lines, overage lines (LIM-05)
 * and, for an upgrade, one pro-rated line. Discounts are spread over the
 * positive lines in proportion (largest remainder) to give each line its
 * net amount and tax, as the tax authority needs.
 */
class Invoicing
{
    public const NUMBER_TYPE = 'core.billing_invoice';

    public const REFERENCE_TYPE = 'billing.invoice';

    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
        private readonly Numbering $numbering,
        private readonly FiscalQueue $fiscal,
    ) {}

    /**
     * Invoice lines from a quote (PriceCalculator) for one period.
     *
     * @param  array<string, mixed>  $quote
     * @param  list<OverageLine>  $overage
     * @return list<array<string, mixed>>
     */
    public function linesFromQuote(array $quote, array $overage = []): array
    {
        $months = (int) ($quote['months'] ?? 1);
        $modules = CatalogueModule::on(PlatformDatabase::READ)->pluck('name', 'key');
        $bundles = CatalogueBundle::on(PlatformDatabase::READ)->pluck('name', 'key');
        $lines = [];

        foreach ($quote['lines'] as $line) {
            $lines[] = [
                'type' => $line['type'],
                'key' => $line['key'],
                'name' => ($line['type'] === 'bundle' ? $bundles : $modules)[$line['key']] ?? null,
                'unit' => $line['unit'],
                'quantity' => (int) $line['quantity'],
                'billable' => (int) $line['billable'],
                'months' => $months,
                'unit_amount' => (int) $line['unit_amount'],
                'amount' => (int) $line['amount'] * $months,
            ];
        }

        foreach ($overage as $extra) {
            $lines[] = [
                'type' => 'overage',
                'key' => $extra->key,
                'name' => $modules[$extra->key] ?? null,
                'unit' => $extra->unit,
                'quantity' => $extra->quantity,
                'billable' => $extra->quantity,
                'months' => null,
                'unit_amount' => $extra->unitAmount,
                'amount' => $extra->amount,
                'days' => $extra->days,
                'period_days' => $extra->periodDays,
            ];
        }

        if ((int) ($quote['annual_discount'] ?? 0) > 0) {
            $lines[] = ['type' => 'annual_discount', 'key' => null, 'name' => null, 'amount' => -(int) $quote['annual_discount'], 'months' => $months - (int) $quote['months_charged']];
        }

        if ((int) ($quote['coupon_discount'] ?? 0) > 0) {
            $lines[] = ['type' => 'coupon', 'key' => $quote['coupon']['code'] ?? null, 'name' => null, 'amount' => -(int) $quote['coupon_discount']];
        }

        return $lines;
    }

    /** @return list<array<string, mixed>> the one line of a pro-rated upgrade */
    public function prorationLines(int $amount, int $remainingDays, int $periodDays): array
    {
        return [['type' => 'proration', 'key' => null, 'name' => null, 'quantity' => $remainingDays, 'period_days' => $periodDays, 'amount' => $amount]];
    }

    /**
     * Issue an invoice to the current (customer) tenant for $subscription.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function issue(Subscription $subscription, string $kind, array $lines, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $dueAt): BillingInvoice
    {
        $customerTenantId = $this->tenants->require();
        $customer = $this->customer();
        $issuedAt = CarbonImmutable::now();

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $kind, $lines, $periodStart, $periodEnd, $dueAt, $customerTenantId, $customer, $issuedAt) {
            [$invoice, $seller] = $this->operator->run(function () use ($subscription, $kind, $lines, $periodStart, $periodEnd, $dueAt, $customerTenantId, $customer, $issuedAt) {
                $company = $this->operator->company($subscription->country);
                [$taxCodeId, $rate] = $this->taxFor($company, $issuedAt);
                $lines = self::withTax($lines, $rate);
                $positive = array_filter($lines, fn (array $line) => $line['amount'] > 0);
                $subtotal = array_sum(array_column($positive, 'amount'));
                $total = array_sum(array_column($lines, 'amount'));

                $invoice = OperatorInvoice::create([
                    'id' => (string) Str::uuid7(),
                    'company_id' => $company->id,
                    'customer_tenant_id' => $customerTenantId,
                    'subscription_id' => $subscription->id,
                    'number' => $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, at: $issuedAt))->number,
                    'kind' => $kind,
                    'status' => BillingInvoice::OPEN,
                    'currency' => $subscription->currency,
                    'subtotal_minor' => $subtotal,
                    'discount_minor' => $subtotal - $total,
                    'tax_minor' => array_sum(array_map(fn (array $line) => (int) ($line['tax_minor'] ?? 0), $lines)),
                    'total_minor' => $total,
                    'tax_code_id' => $taxCodeId,
                    'tax_rate' => $rate,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'issued_at' => $issuedAt,
                    'due_at' => $dueAt,
                    'lines' => $lines,
                    'customer' => $customer,
                ]);

                // Concept note 7.2: the operator company's fiscal queue (nothing when transmission is off).
                $this->fiscal->enqueue(BillingFiscalSource::KEY, 'sale', $invoice->id, $company->id);

                return [$invoice, [
                    'name' => $company->name,
                    'legal_name' => $company->legal_name,
                    'tax_id' => $company->tax_id,
                    'address' => $company->address,
                    'country' => $company->country,
                ]];
            });

            return BillingInvoice::create([
                'subscription_id' => $subscription->id,
                'operator_invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'kind' => $invoice->kind,
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'subtotal_minor' => $invoice->subtotal_minor,
                'discount_minor' => $invoice->discount_minor,
                'tax_minor' => $invoice->tax_minor,
                'total_minor' => $invoice->total_minor,
                'period_start' => $invoice->period_start->toDateString(),
                'period_end' => $invoice->period_end->toDateString(),
                'issued_at' => $invoice->issued_at,
                'due_at' => $invoice->due_at,
                'lines' => $invoice->lines,
                'seller' => $seller,
                'customer' => $customer,
            ]);
        });
    }

    /** The customer as invoiced: the tenant's name and its first company's tax id, address and country. */
    private function customer(): array
    {
        $tenant = Tenant::query()->firstOrFail();
        $company = Company::query()->whereNull('archived_at')->orderBy('created_at')->first();

        return [
            'name' => $company?->legal_name ?: $tenant->name,
            'tax_id' => $company?->tax_id,
            'address' => $company?->address,
            'country' => $company?->country,
        ];
    }

    /**
     * The operator company's billing tax code (config billing.tax_codes,
     * a country pack code) and its rate on $at: [code id, rate] with a
     * null rate when no confirmed rate exists (never guessed, CP-02).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function taxFor(Company $company, CarbonImmutable $at): array
    {
        $code = config("billing.tax_codes.{$company->country}");
        $taxCode = $code === null ? null : TaxCode::query()->where('company_id', $company->id)->where('code', $code)->whereNull('archived_at')->first();

        if ($taxCode === null) {
            return [null, null];
        }

        $rate = $taxCode->isExempt() ? '0' : $taxCode->rateOn($at)?->rate;

        return [$taxCode->id, $rate === null ? null : (string) $rate];
    }

    /**
     * Spread the negative lines (discounts) over the positive ones in
     * proportion to their amounts (largest remainder), then tax each
     * positive line's net amount at $rate (tax-inclusive, half up). With
     * no rate, the tax is left out (null): the fiscal queue holds the
     * invoice until the rate is entered.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function withTax(array $lines, ?string $rate): array
    {
        $discount = -array_sum(array_map(fn (array $line) => min(0, (int) $line['amount']), $lines));
        $positive = array_keys(array_filter($lines, fn (array $line) => (int) $line['amount'] > 0));
        $base = array_sum(array_map(fn (int $i) => (int) $lines[$i]['amount'], $positive));
        $shares = array_fill_keys($positive, 0);

        if ($discount > 0 && $base > 0) {
            $discount = min($discount, $base);
            $remainders = [];

            foreach ($positive as $i) {
                $shares[$i] = intdiv($discount * (int) $lines[$i]['amount'], $base);
                $remainders[$i] = ($discount * (int) $lines[$i]['amount']) % $base;
            }

            arsort($remainders);

            foreach (array_slice(array_keys($remainders), 0, $discount - array_sum($shares)) as $i) {
                $shares[$i]++;
            }
        }

        $number = 0;

        foreach ($lines as $i => $line) {
            $lines[$i]['line_no'] = ++$number;

            if ((int) $line['amount'] <= 0) {
                continue;
            }

            $net = (int) $line['amount'] - $shares[$i];
            $lines[$i]['discount_minor'] = $shares[$i];
            $lines[$i]['net_minor'] = $net;
            $lines[$i]['tax_rate'] = $rate;
            $lines[$i]['tax_minor'] = $rate === null ? null : self::inclusiveTax($net, $rate);
        }

        return array_values($lines);
    }

    /** Tax included in $gross at $rate percent, half up, in integer maths (rate to 4 decimals). */
    public static function inclusiveTax(int $gross, string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($rate), 2), 2, '');
        $scaled = (int) $whole * 10000 + (int) str_pad(substr(preg_replace('/\D/', '', $fraction), 0, 4), 4, '0');

        if ($scaled <= 0 || $gross <= 0) {
            return 0;
        }

        $denominator = 1_000_000 + $scaled;

        return intdiv(2 * $gross * $scaled + $denominator, 2 * $denominator);
    }
}
