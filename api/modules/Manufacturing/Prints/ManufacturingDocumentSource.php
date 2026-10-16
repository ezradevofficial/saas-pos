<?php

namespace Modules\Manufacturing\Prints;

use App\Core\DocumentTemplates\DocumentData;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\Samples;
use Modules\Manufacturing\Models\Order;

/**
 * TPL-01, MFG-24: the data sources of `manufacturing.production_order` and
 * `manufacturing.pick_list` (real orders, OrderPrints), with sample data
 * for the designer.
 */
final class ManufacturingDocumentSource implements RecordSource
{
    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function documentFields(): array
    {
        return OrderPrints::DOCUMENT_FIELDS;
    }

    public function lineColumns(): array
    {
        return $this->type === OrderPrints::PICK_LIST ? OrderPrints::PICK_COLUMNS : OrderPrints::ORDER_COLUMNS;
    }

    public function live(): bool
    {
        return true;
    }

    public function load(string $recordId): ?DocumentData
    {
        $order = Order::query()->with('planner')->find($recordId);

        return $order === null ? null : app(OrderPrints::class)->document($order, $this->type);
    }

    public function sample(?string $country): array
    {
        $base = Samples::base($this->type, $country, null);
        $currency = array_key_first($base['currencies']);
        unset($base['scale']);
        $pick = $this->type === OrderPrints::PICK_LIST;
        $line = fn (string $name, string $code, string $unit, string $qty, string $issued, string $open) => $pick
            ? ['item_name' => $name, 'item_code' => $code, 'unit' => $unit, 'required' => $qty, 'issued' => $issued, 'to_issue' => $open, 'custom' => []]
            : ['item_name' => $name, 'item_code' => $code, 'qty' => $qty, 'unit' => $unit, 'custom' => []];

        return [
            ...$base,
            'customer' => null,
            'fiscal' => null,
            'document' => [
                'number' => 'MO-BR1-2026-00001', 'date' => '2026-10-09 10:15', 'currency' => $currency,
                'product' => __('manufacturing.sample.product'), 'product_code' => 'BREAD-800', 'quantity' => '200', 'unit' => 'EA',
                'planned_start' => '2026-10-10', 'planned_end' => '2026-10-10', 'version' => '2',
                'status' => __('manufacturing.statuses.released'), 'planned_by' => __('manufacturing.sample.planned_by'), 'note' => null, 'custom' => [],
            ],
            'lines' => [
                $line(__('manufacturing.sample.material_1'), 'FLOUR', 'KG', '100', '60', '40'),
                $line(__('manufacturing.sample.material_2'), 'YEAST', 'KG', '2', '2', '0'),
            ],
            'totals' => null,
            'payments' => [],
            'change' => null,
        ];
    }
}
