<?php

namespace Modules\POS\Documents;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\FiscalRules;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;

/**
 * POS-06, TPL-01: a sale or a refund in the template data shape
 * (App\Core\DocumentTemplates\DataSource): amounts in minor units with
 * their currency, dates in the branch's time zone, business record names
 * as stored with the sale (item names as sold), and the tax authority's
 * answer (POS-10) when the company's country pack requires it (TPL-03).
 * Reads core master data (items, units, tax codes, parties, payment
 * methods), never another module's tables.
 */
class ReceiptData
{
    public function __construct(
        private readonly FiscalRules $rules,
        private readonly FiscalQueue $queue,
        private readonly CurrencyDecimals $decimals,
    ) {}

    public function sale(Sale $sale): DocumentData
    {
        $sale->loadMissing(['lines', 'payments']);
        [$company, $branch, $location] = $this->place($sale->company_id, $sale->branch_id, $sale->location_id);
        $currency = $sale->currency;
        $lines = $sale->lines;
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->unique())->get(['id', 'code', 'custom'])->keyBy('id');
        $uoms = Uom::query()->whereIn('id', $lines->pluck('uom_id')->unique())->get(['id', 'code'])->keyBy('id');
        $customer = $sale->customer_id === null ? null : Party::query()->find($sale->customer_id);
        $methods = PaymentMethod::query()->whereIn('id', $sale->payments->pluck('payment_method_id')->unique())->pluck('name', 'id');
        $dual = $sale->base_currency !== $currency ? $this->money($sale->base_total_minor, $sale->base_currency) : null;

        $data = [
            ...$this->common($company, $branch, $location, $customer),
            'currencies' => $this->currencies([$currency, $sale->change_currency, $sale->base_currency, ...$sale->payments->pluck('currency')->all()]),
            'document' => [
                'number' => $sale->receipt_number,
                'date' => $this->date($sale->sold_at, $branch, $company),
                'cashier' => (string) User::query()->whereKey($sale->cashier_id)->value('name'),
                'currency' => $currency,
            ],
            'lines' => $lines->map(fn (SaleLine $line) => [
                'item_name' => $line->item_name,
                'item_code' => $items->get($line->item_id)?->code,
                'qty' => self::decimal((string) $line->qty),
                'unit' => $uoms->get($line->uom_id)?->code,
                'unit_price' => $this->money($line->unit_price_minor, $currency),
                'discount' => $this->money($line->discount_minor, $currency),
                'tax_rate' => $line->tax_rate === null ? null : self::decimal((string) $line->tax_rate),
                'tax' => $this->money($line->tax_minor, $currency),
                'total' => $this->money($line->total_minor, $currency),
                // CF-03: the item's custom fields, as display text.
                'custom' => DataSources::customValues('item', $items->get($line->item_id)?->custom),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($sale->subtotal_minor, $currency),
                'discount' => $this->money($sale->discount_minor, $currency),
                'tax' => $this->money($sale->tax_minor, $currency),
                'total' => $this->money($sale->total_minor, $currency),
                'dual' => $dual,
                'tax_lines' => $this->taxLines($lines->map(fn (SaleLine $l) => [$l->tax_code_id, $l->tax_rate, (int) $l->tax_minor]), $currency),
            ],
            'payments' => $sale->payments->map(fn ($payment) => [
                'method' => (string) ($methods[$payment->payment_method_id] ?? ''),
                'amount' => $this->money($payment->amount_minor, $payment->currency),
                'reference' => $payment->provider_reference,
            ])->values()->all(),
            'change' => (int) $sale->change_minor > 0 ? $this->money($sale->change_minor, $sale->change_currency) : null,
            'fiscal' => $this->fiscal('pos.receipt', $company, 'sale', $sale->id),
        ];

        return new DocumentData('pos.receipt', $sale->id, $data, $company->id, $branch->id, $company->country, $this->email($customer));
    }

    public function refund(Refund $refund): DocumentData
    {
        $refund->loadMissing(['lines', 'payments', 'sale']);
        $sale = $refund->sale;
        [$company, $branch, $location] = $this->place($refund->company_id, $refund->branch_id, $refund->location_id);
        $currency = $refund->currency;
        $saleLines = SaleLine::query()->whereIn('id', $refund->lines->pluck('sale_line_id'))->get()->keyBy('id');
        $customer = $sale->customer_id === null ? null : Party::query()->find($sale->customer_id);
        $methods = PaymentMethod::query()->whereIn('id', $refund->payments->pluck('payment_method_id')->unique())->pluck('name', 'id');

        $lines = $refund->lines->map(function (RefundLine $line) use ($saleLines, $currency) {
            $sold = $saleLines->get($line->sale_line_id);

            return [
                'item_name' => $sold?->item_name,
                'item_code' => null,
                'qty' => self::decimal((string) $line->qty),
                'unit' => null,
                'unit_price' => $sold === null ? null : $this->money($sold->unit_price_minor, $currency),
                'discount' => null,
                'tax_rate' => $sold?->tax_rate === null ? null : self::decimal((string) $sold->tax_rate),
                'tax' => $this->money($line->tax_minor, $currency),
                'total' => $this->money($line->total_minor, $currency),
                'custom' => [],
                '_tax' => [$sold?->tax_code_id, $sold?->tax_rate, (int) $line->tax_minor],
            ];
        });

        $data = [
            ...$this->common($company, $branch, $location, $customer),
            'currencies' => $this->currencies([$currency, ...$refund->payments->pluck('currency')->all()]),
            'document' => [
                'number' => $refund->receipt_number,
                'date' => $this->date($refund->refunded_at, $branch, $company),
                'cashier' => (string) User::query()->whereKey($refund->cashier_id)->value('name'),
                'currency' => $currency,
                'reference' => $sale->receipt_number,
                'reason' => $refund->reason,
            ],
            'lines' => $lines->map(fn (array $line) => array_diff_key($line, ['_tax' => true]))->values()->all(),
            'totals' => [
                'subtotal' => null,
                'discount' => null,
                'tax' => $this->money($refund->tax_minor, $currency),
                'total' => $this->money($refund->total_minor, $currency),
                'dual' => null,
                'tax_lines' => $this->taxLines($lines->pluck('_tax'), $currency),
            ],
            'payments' => $refund->payments->map(fn ($payment) => [
                'method' => (string) ($methods[$payment->payment_method_id] ?? ''),
                'amount' => $this->money($payment->amount_minor, $payment->currency),
                'reference' => $payment->provider_reference,
            ])->values()->all(),
            'change' => null,
            'fiscal' => $this->fiscal('pos.refund_receipt', $company, 'refund', $refund->id),
        ];

        return new DocumentData('pos.refund_receipt', $refund->id, $data, $company->id, $branch->id, $company->country, $this->email($customer));
    }

    /** @return array{0: Company, 1: Branch, 2: Location} */
    private function place(string $companyId, string $branchId, string $locationId): array
    {
        return [
            Company::query()->findOrFail($companyId),
            Branch::query()->findOrFail($branchId),
            Location::query()->findOrFail($locationId),
        ];
    }

    private function common(Company $company, Branch $branch, Location $location, ?Party $customer): array
    {
        return [
            'company' => [
                'name' => $company->name,
                'legal_name' => $company->legal_name ?: $company->name,
                'tax_id' => $company->tax_id,
                'address' => self::address($company->address),
                'phone' => null,
                'email' => null,
                'country' => $company->country,
                // BR-02: the tenant logo joins here once branding stores one.
                'logo' => null,
                'custom' => (array) ($company->getAttribute('custom') ?? []),
            ],
            'branch' => ['name' => $branch->name, 'code' => $branch->code, 'address' => self::address($branch->address)],
            'location' => ['name' => $location->name, 'code' => $location->code],
            'customer' => $customer === null ? null : [
                'name' => $customer->name,
                'tax_id' => $customer->tax_id,
                'phone' => $customer->phoneNumbers()[0] ?? null,
                'email' => $this->email($customer),
                'address' => self::address($customer->addresses[0] ?? null),
                'tags' => array_values((array) $customer->tags),
                // CF-03: the party's custom fields, as display text.
                'custom' => DataSources::customValues('party', $customer->custom),
            ],
        ];
    }

    /** POS-10, TPL-03: the authority's answer, when the country pack needs it on this document. */
    private function fiscal(string $type, Company $company, string $documentType, string $id): ?array
    {
        $authority = $this->rules->authorityFor($type, $company->country);

        if ($authority === null) {
            return null;
        }

        $status = $this->queue->statusFor(PosFiscalSource::KEY, $documentType, $id);
        $answer = (array) ($status['authority'] ?? []);

        return [
            'authority' => $authority,
            'status' => $status === null ? ($this->queue->transmits($company->id) ? 'pending' : 'off') : $status['status'],
            'invoice_number' => $status === null || $status['invoice_number'] === null ? null : (string) $status['invoice_number'],
            'receipt_number' => self::text($answer['receipt_number'] ?? null),
            'receipt_signature' => self::text($answer['receipt_signature'] ?? null),
            'internal_data' => self::text($answer['internal_data'] ?? null),
            'control_unit_id' => self::text($answer['control_unit_id'] ?? null),
            'authority_time' => self::text($answer['authority_time'] ?? null),
            'qr' => self::text($answer['qr'] ?? null),
        ];
    }

    /**
     * One line per tax code and rate: the code's name as entered and the tax.
     *
     * @param  Collection<int, array{0: ?string, 1: mixed, 2: int}>  $taxes
     */
    private function taxLines(Collection $taxes, string $currency): array
    {
        $sums = [];

        foreach ($taxes as [$codeId, $rate, $tax]) {
            if ($codeId === null) {
                continue;
            }

            $key = $codeId.'|'.($rate === null ? '' : self::decimal((string) $rate));
            $sums[$key] = ($sums[$key] ?? 0) + $tax;
        }

        $names = TaxCode::query()->whereIn('id', array_unique(array_map(fn ($k) => strstr($k, '|', true), array_keys($sums))))->pluck('name', 'id');
        $lines = [];

        foreach ($sums as $key => $tax) {
            [$codeId, $rate] = explode('|', $key, 2);
            $lines[] = ['name' => (string) ($names[$codeId] ?? ''), 'rate' => $rate === '' ? null : $rate, 'tax' => $this->money($tax, $currency)];
        }

        return $lines;
    }

    /** @param list<?string> $codes */
    private function currencies(array $codes): array
    {
        $out = [];

        foreach (array_unique(array_filter($codes)) as $code) {
            $out[$code] = $this->decimals->for($code);
        }

        return $out;
    }

    private function money(int|string $minor, string $currency): array
    {
        return ['minor' => (string) $minor, 'currency' => $currency];
    }

    private function date(CarbonInterface $at, Branch $branch, Company $company): string
    {
        return $at->copy()->setTimezone($branch->timezone ?: ($company->timezone ?: 'UTC'))->format('Y-m-d H:i');
    }

    private function email(?Party $customer): ?string
    {
        $email = $customer?->emails[0]['address'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /** "2.000000" → "2", "12.5000" → "12.5". */
    public static function decimal(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function address(mixed $address): ?string
    {
        if (is_string($address)) {
            return $address === '' ? null : $address;
        }

        if (! is_array($address)) {
            return null;
        }

        $parts = array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', array_values($address)), fn ($v) => $v !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
