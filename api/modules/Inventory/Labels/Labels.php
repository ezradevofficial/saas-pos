<?php

namespace Modules\Inventory\Labels;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\Inventory\Models\Batch;

/**
 * INV-21: barcode labels, one document per copy, printed through the
 * template that applies at the price list's company (else the tenant's),
 * TPL-05. A label's price is the list's price for the item and unit today
 * (blank without a list or a price); its barcode is the item's barcode for
 * that unit, else its first barcode, else its code. Audited as
 * `inventory.label.print` with what was printed.
 */
class Labels
{
    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{item_id: string, uom_id?: ?string, batch_id?: ?string, copies: int}>  $labels
     * @return non-empty-list<DocumentData>
     */
    public function documents(array $labels, ?PriceList $list): array
    {
        $company = $list === null ? null : Company::query()->find($list->company_id);
        $documents = [];

        foreach ($labels as $label) {
            $item = Item::query()->with('baseUom')->findOrFail($label['item_id']);
            $uom = isset($label['uom_id']) ? Uom::query()->find($label['uom_id']) : $item->baseUom;
            $batch = isset($label['batch_id']) ? Batch::query()->where('item_id', $item->id)->find($label['batch_id']) : null;
            $data = $this->data($item, $uom ?? $item->baseUom, $batch, $list, $company);

            for ($copy = 0; $copy < (int) $label['copies']; $copy++) {
                $documents[] = new DocumentData(LabelSource::TYPE, $item->id, $data, $company?->id, null, $company?->country);
            }
        }

        $this->auditor->record('inventory.label.print', null, null, [
            'labels' => array_map(fn (array $l) => ['item_id' => $l['item_id'], 'copies' => (int) $l['copies']], $labels),
            'price_list_id' => $list?->id,
        ]);

        return $documents;
    }

    private function data(Item $item, Uom $uom, ?Batch $batch, ?PriceList $list, ?Company $company): array
    {
        $barcode = ItemBarcode::query()->where('item_id', $item->id)->where('uom_id', $uom->id)->value('barcode')
            ?? ItemBarcode::query()->where('item_id', $item->id)->orderBy('barcode')->value('barcode')
            ?? (string) $item->code;
        $price = $list === null ? null : app(PriceResolver::class)->priceFor($item, $uom->id, $list, CarbonImmutable::now());
        $currency = $list?->currency;

        return [
            'currencies' => $currency === null ? [] : [$currency => $this->decimals->for($currency)],
            'company' => $company === null ? null : [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => null, 'phone' => null, 'email' => null, 'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => null,
            'location' => null,
            'customer' => null,
            'document' => [
                'number' => (string) $item->code,
                'date' => CarbonImmutable::now($company?->timezone ?: 'UTC')->format('Y-m-d'),
                'currency' => $currency,
                'item_name' => $item->name,
                'item_code' => (string) $item->code,
                'barcode' => (string) $barcode,
                'unit' => (string) $uom->code,
                'price' => $price === null ? null : ['minor' => $price->money->minor(), 'currency' => $price->money->currency()],
                'batch' => $batch?->batch_number,
                'expires_on' => $batch?->expires_on?->toDateString(),
            ],
            'lines' => [],
            'totals' => null,
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];
    }
}
