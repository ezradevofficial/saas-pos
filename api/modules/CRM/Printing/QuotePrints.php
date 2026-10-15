<?php

namespace Modules\CRM\Printing;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Collection;
use Modules\CRM\Http\Resources\Refs;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\QuoteLine;

/**
 * TPL-01, CRM-22: a quote in the shape every template reads (DataSource):
 * money as `{minor, currency}`, business names as entered, the customer
 * in the `customer` group, lines with discount and tax rate, totals with
 * their tax lines. A draft prints without a number. Printed documents are
 * black on white whatever the theme (the renderer's job).
 */
class QuotePrints
{
    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function quote(Quote $quote): DocumentData
    {
        $company = Company::query()->findOrFail($quote->company_id);
        $branch = Branch::query()->find($quote->branch_id);
        $party = Party::query()->find($quote->party_id);
        $currency = $quote->currency;
        $lines = QuoteLine::query()->where('quote_id', $quote->id)->orderBy('line_no')->get();
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->filter()->unique())->pluck('code', 'id');
        $units = Uom::query()->whereIn('id', $lines->pluck('uom_id')->filter()->unique())->pluck('code', 'id');
        $email = $party?->emails[0]['address'] ?? null;
        $m = fn (int|string $minor) => ['minor' => (string) $minor, 'currency' => $currency];

        $data = [
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => $branch === null ? null : ['name' => $branch->name, 'code' => $branch->code, 'address' => null],
            'location' => null,
            'customer' => $party === null ? null : [
                'name' => $party->name, 'tax_id' => $party->tax_id, 'phone' => $party->phoneNumbers()[0] ?? null,
                'email' => is_string($email) ? $email : null, 'address' => null, 'tags' => array_values((array) $party->tags), 'custom' => [],
            ],
            'currencies' => [$currency => $this->decimals->for($currency)],
            'document' => [
                'number' => (string) ($quote->number ?? __('crm.statuses.draft')),
                'date' => $quote->quote_date->toDateString(),
                'currency' => $currency,
                'valid_until' => $quote->valid_until->toDateString(),
                'reference' => $quote->opportunity?->name,
                'terms' => $quote->terms,
            ],
            'lines' => $lines->map(fn (QuoteLine $line) => [
                'item_name' => $line->description,
                'item_code' => $line->item_id === null ? null : ($items[$line->item_id] ?? null),
                'qty' => Refs::decimal($line->qty),
                'unit' => $line->uom_id === null ? null : ($units[$line->uom_id] ?? null),
                'unit_price' => $m($line->unit_price_minor),
                'discount' => $m($line->discount_minor),
                'tax_rate' => Refs::decimal($line->tax_rate),
                'tax' => $m($line->tax_minor),
                'total' => $m($line->total_minor),
                'custom' => [],
            ])->values()->all(),
            'totals' => [
                'subtotal' => $m($quote->subtotal_minor),
                'discount' => $m($quote->discount_minor),
                'tax' => $m($quote->tax_minor),
                'total' => $m($quote->total_minor),
                'dual' => null,
                'tax_lines' => $this->taxLines($lines, $currency),
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData(QuoteSource::TYPE, $quote->id, $data, $company->id, $branch?->id, $company->country, is_string($email) ? $email : null);
    }

    /** @param Collection<int, QuoteLine> $lines */
    private function taxLines(Collection $lines, string $currency): array
    {
        $sums = [];

        foreach ($lines as $line) {
            if ($line->tax_code_id !== null) {
                $key = $line->tax_code_id.'|'.(Refs::decimal($line->tax_rate) ?? '');
                $sums[$key] = ($sums[$key] ?? 0) + $line->tax_minor;
            }
        }

        $names = TaxCode::query()->whereIn('id', array_unique(array_map(fn ($key) => strstr($key, '|', true), array_keys($sums))))->pluck('name', 'id');
        $out = [];

        foreach ($sums as $key => $tax) {
            [$codeId, $rate] = explode('|', $key, 2);
            $out[] = ['name' => (string) ($names[$codeId] ?? ''), 'rate' => $rate === '' ? null : $rate, 'tax' => ['minor' => (string) $tax, 'currency' => $currency]];
        }

        return $out;
    }
}
