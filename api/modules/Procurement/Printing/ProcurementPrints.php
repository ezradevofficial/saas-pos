<?php

namespace Modules\Procurement\Printing;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Modules\Procurement\CustomFields\OrderEntity;
use Modules\Procurement\Documents\Receipts;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderLine;

/**
 * TPL-01, PRC-12, PRC-13: the data of a PO and of award and regret letters
 * in the shape every template reads (DataSource): money as `{minor,
 * currency}`, business names as entered. Printed documents are black on
 * white whatever the theme (the renderer's job). The supplier fills the
 * template's `customer` group.
 */
class ProcurementPrints
{
    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function order(Order $order): DocumentData
    {
        $company = Company::query()->findOrFail($order->company_id);
        $branch = Branch::query()->find($order->branch_id);
        $party = Party::query()->find($order->party_id);
        $currency = $order->currency;
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get();
        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->filter()->unique())->pluck('code', 'id');
        $units = Uom::query()->whereIn('id', $lines->pluck('uom_id')->filter()->unique())->pluck('code', 'id');

        $data = [
            ...$this->common($company, $branch, $party),
            'location' => ($location = Location::query()->find($order->location_id)) === null ? null : ['name' => $location->name, 'code' => $location->code],
            'currencies' => [$currency => $this->decimals->for($currency)],
            'document' => [
                'number' => (string) $order->number,
                'date' => $order->order_date->toDateString(),
                'currency' => $currency,
                'supplier' => $party?->name,
                'expected_on' => $order->expected_on?->toDateString(),
                'requisition_ref' => $order->requisition_id === null ? null : Requisition::query()->whereKey($order->requisition_id)->value('number'),
                'delivery_to' => $location?->name,
                'terms' => $order->terms,
                'custom' => DataSources::customValues(OrderEntity::KEY, $order->custom),
            ],
            'lines' => $lines->map(fn (OrderLine $line) => [
                'item_name' => $line->description,
                'item_code' => $line->item_id === null ? null : ($items[$line->item_id] ?? null),
                'qty' => Receipts::decimal((string) $line->qty),
                'unit' => $line->uom_id === null ? null : ($units[$line->uom_id] ?? null),
                'unit_price' => $this->money($line->unit_price_minor, $currency),
                'tax' => $this->money($line->tax_minor, $currency),
                'total' => $this->money($line->total_minor, $currency),
                'custom' => [],
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($order->subtotal_minor, $currency),
                'discount' => $this->money(0, $currency),
                'tax' => $this->money($order->tax_minor, $currency),
                'total' => $this->money($order->total_minor, $currency),
                'dual' => null,
                'tax_lines' => $this->taxLines($lines, $currency),
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData(ProcurementSource::PO, $order->id, $data, $company->id, $branch?->id, $company->country, $this->email($party));
    }

    public function letter(Bid $bid): DocumentData
    {
        $tender = Tender::query()->findOrFail($bid->tender_id);
        $bidder = Bidder::query()->findOrFail($bid->bidder_id);
        $company = Company::query()->findOrFail($tender->company_id);
        $branch = Branch::query()->find($tender->branch_id);
        $award = $bid->outcome === Bid::AWARDED;
        $type = $award ? ProcurementSource::AWARD : ProcurementSource::REGRET;
        $currency = (string) ($bid->currency ?? $tender->currency);
        $tenderLines = TenderLine::query()->where('tender_id', $tender->id)->get()->keyBy('id');
        $units = Uom::query()->whereIn('id', $tenderLines->pluck('uom_id')->filter()->unique())->pluck('code', 'id');

        $data = [
            ...$this->common($company, $branch, null),
            'customer' => ['name' => $bidder->name, 'tax_id' => $bidder->tax_id, 'phone' => null, 'email' => $bidder->email, 'address' => null, 'tags' => [], 'custom' => []],
            'currencies' => [$currency => $this->decimals->for($currency)],
            'document' => [
                'number' => (string) $tender->number,
                'date' => ($tender->awarded_at ?? $tender->opened_at)?->setTimezone($company->timezone ?: 'UTC')->toDateString(),
                'currency' => $currency,
                'subject' => __('procurement.letters.'.($award ? 'award' : 'regret').'_subject', ['title' => $tender->title]),
                'tender_ref' => (string) $tender->number,
                'bidder' => $bidder->name,
                'tender_title' => $tender->title,
                'awarded_total' => $award ? $this->money((int) $bid->total_minor, $currency) : null,
            ],
            'lines' => $award ? array_map(fn (array $line) => [
                'item_name' => $tenderLines->get($line['tender_line_id'])?->description,
                'item_code' => null,
                'qty' => Receipts::decimal((string) $tenderLines->get($line['tender_line_id'])?->qty),
                'unit' => ($uom = $tenderLines->get($line['tender_line_id'])?->uom_id) === null ? null : ($units[$uom] ?? null),
                'unit_price' => $this->money((int) $line['unit_price_minor'], $currency),
                'tax' => null,
                'total' => $this->money((int) $line['amount_minor'], $currency),
                'custom' => [],
            ], $bid->lines ?? []) : [],
            'totals' => $award ? [
                'subtotal' => $this->money((int) $bid->total_minor, $currency), 'discount' => $this->money(0, $currency), 'tax' => $this->money(0, $currency),
                'total' => $this->money((int) $bid->total_minor, $currency), 'dual' => null, 'tax_lines' => [],
            ] : null,
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData($type, $bid->id, $data, $company->id, $branch?->id, $company->country, $bidder->email);
    }

    private function common(Company $company, ?Branch $branch, ?Party $party): array
    {
        return [
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => $branch === null ? null : ['name' => $branch->name, 'code' => $branch->code, 'address' => null],
            'location' => null,
            'customer' => $party === null ? null : [
                'name' => $party->name, 'tax_id' => $party->tax_id, 'phone' => $party->phoneNumbers()[0] ?? null,
                'email' => $this->email($party), 'address' => null, 'tags' => array_values((array) $party->tags), 'custom' => [],
            ],
        ];
    }

    private function taxLines($lines, string $currency): array
    {
        $sums = [];

        foreach ($lines as $line) {
            if ($line->tax_code_id === null) {
                continue;
            }

            $key = $line->tax_code_id.'|'.($line->tax_rate === null ? '' : Receipts::decimal((string) $line->tax_rate));
            $sums[$key] = ($sums[$key] ?? 0) + (int) $line->tax_minor;
        }

        $names = TaxCode::query()->whereIn('id', array_unique(array_map(fn ($k) => strstr($k, '|', true), array_keys($sums))))->pluck('name', 'id');
        $out = [];

        foreach ($sums as $key => $tax) {
            [$codeId, $rate] = explode('|', $key, 2);
            $out[] = ['name' => (string) ($names[$codeId] ?? ''), 'rate' => $rate === '' ? null : $rate, 'tax' => $this->money($tax, $currency)];
        }

        return $out;
    }

    private function money(int|string $minor, string $currency): array
    {
        return ['minor' => (string) $minor, 'currency' => $currency];
    }

    private function email(?Party $party): ?string
    {
        $email = $party?->emails[0]['address'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }
}
