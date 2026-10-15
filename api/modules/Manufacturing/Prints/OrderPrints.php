<?php

namespace Modules\Manufacturing\Prints;

use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentData;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-24, TPL-01: a production order's data for its document templates,
 * in the shape every template reads (DataSource):
 * `manufacturing.production_order` (the product, quantities, dates and
 * the materials it requires, its custom fields) and
 * `manufacturing.pick_list` (what to pick at the location: required,
 * issued and still to issue per material). No values. Business names as
 * entered; times in the company's time zone.
 */
class OrderPrints
{
    public const PRODUCTION_ORDER = 'manufacturing.production_order';

    public const PICK_LIST = 'manufacturing.pick_list';

    /** `?document=` => document type. */
    public const TYPES = ['production_order' => self::PRODUCTION_ORDER, 'pick_list' => self::PICK_LIST];

    public const DOCUMENT_FIELDS = [
        'document.product' => 'text', 'document.product_code' => 'text', 'document.quantity' => 'qty', 'document.unit' => 'text',
        'document.planned_start' => 'date', 'document.planned_end' => 'date', 'document.version' => 'text', 'document.status' => 'text',
        'document.planned_by' => 'text', 'document.note' => 'text',
    ];

    public const ORDER_COLUMNS = ['item_name' => 'text', 'item_code' => 'text', 'qty' => 'qty', 'unit' => 'text'];

    public const PICK_COLUMNS = ['item_name' => 'text', 'item_code' => 'text', 'unit' => 'text', 'required' => 'qty', 'issued' => 'qty', 'to_issue' => 'qty'];

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    public function document(Order $order, string $type): DocumentData
    {
        $company = Company::query()->findOrFail($order->company_id);
        $branch = Branch::query()->find($order->branch_id);
        $location = Location::query()->find($order->location_id);
        $materials = OrderMaterial::query()->where('order_id', $order->id)->orderBy('line_no')->get();
        $items = Item::query()->whereKey([...$materials->pluck('item_id')->all(), $order->item_id])->get()->keyBy('id');
        $uoms = Uom::query()->whereKey([...$materials->pluck('uom_id')->all(), $order->uom_id])->get()->keyBy('id');
        $pick = $type === self::PICK_LIST;
        $currency = (string) ($order->currency ?? $company->base_currency);
        $zone = $company->timezone ?: 'UTC';

        $rows = $materials->map(function (OrderMaterial $material) use ($items, $uoms, $pick) {
            $item = $items->get($material->item_id);
            $row = ['item_name' => $item?->name, 'item_code' => $item?->code, 'unit' => $uoms->get($material->uom_id)?->code, 'custom' => []];
            $consumed = BigDecimal::of((string) $material->qty_issued_base)->minus((string) $material->qty_returned_base);

            return $pick
                ? [...$row, 'required' => Qty::show($material->qty_required_base), 'issued' => Qty::show($consumed),
                    'to_issue' => Qty::show(BigDecimal::max(BigDecimal::zero(), BigDecimal::of((string) $material->qty_required_base)->minus($consumed)))]
                : [...$row, 'qty' => Qty::show($material->qty_required_base)];
        })->values()->all();

        $product = $items->get($order->item_id);
        $data = [
            'currencies' => [$currency => $this->decimals->for($currency)],
            'company' => [
                'name' => $company->name, 'legal_name' => $company->legal_name ?: $company->name, 'tax_id' => $company->tax_id,
                'address' => is_string($company->address) ? $company->address : null, 'phone' => null, 'email' => null,
                'country' => $company->country, 'logo' => null, 'custom' => [],
            ],
            'branch' => $branch === null ? null : ['name' => $branch->name, 'code' => $branch->code, 'address' => null],
            'location' => $location === null ? null : ['name' => $location->name, 'code' => $location->code],
            'document' => [
                'number' => $order->number,
                'date' => $order->created_at->setTimezone($zone)->format('Y-m-d H:i'),
                'currency' => $currency,
                'product' => $product?->name,
                'product_code' => $product?->code,
                'quantity' => Qty::show($order->qty),
                'unit' => $uoms->get($order->uom_id)?->code,
                'planned_start' => $order->planned_start?->format('Y-m-d'),
                'planned_end' => $order->planned_end?->format('Y-m-d'),
                'version' => (string) BomVersion::query()->whereKey($order->bom_version_id)->value('version_no'),
                'status' => __('manufacturing.statuses.'.$order->status),
                'planned_by' => $order->planner?->name,
                'note' => $order->note,
                'custom' => DataSources::customValues(OrderEntity::KEY, $order->custom),
            ],
            'customer' => null,
            'lines' => $rows,
            'totals' => null,
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];

        return new DocumentData($type, $order->id, $data, $company->id, $branch?->id, $company->country);
    }
}
