<?php

namespace Modules\Manufacturing\Orders;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Events\ProductionReported;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderOutput;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReportLine;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;

/**
 * MFG-13: production reported against a running order, under its row
 * lock: good and scrap quantities in the order's unit (at least one
 * positive), a scrap reason with scrap, by-product quantities (expected
 * outputs of the order, base unit) and labour minutes (default: the
 * version's minutes for the good quantity, half up). Any number of
 * reports, partial or beyond the planned quantity. The order's totals and
 * outputs follow; an `inventory`-ledger report waits for its costing
 * (Costing, at once when every material is valued).
 */
class Reports
{
    public function __construct(
        private readonly Units $units,
        private readonly DocumentNumbers $numbers,
        private readonly Materials $materials,
        private readonly Costing $costing,
        private readonly Orders $orders,
    ) {}

    /** @param array<string, mixed> $data */
    public function report(Order $order, array $data, User $by): Report
    {
        return Errors::transaction(function () use ($order, $data, $by) {
            $order = $this->materials->running($order);
            $good = $this->quantity($data['qty_good'] ?? '0', 'qty_good', $order->uom_id);
            $scrap = $this->quantity($data['qty_scrap'] ?? '0', 'qty_scrap', $order->uom_id);
            $byProducts = $this->byProducts($order, $data['by_products'] ?? []);

            if ($good->isZero() && $scrap->isZero() && $byProducts === []) {
                throw Errors::make(422, 'qty_invalid', field: 'qty_good');
            }

            if ($scrap->isPositive() && empty($data['scrap_reason'])) {
                throw Errors::make(422, 'scrap_reason_required', field: 'scrap_reason');
            }

            $goodBase = Units::baseOf($good, (string) $order->qty, (string) $order->qty_base);
            $scrapBase = Units::baseOf($scrap, (string) $order->qty, (string) $order->qty_base);
            $version = BomVersion::query()->findOrFail($order->bom_version_id);
            $minutes = array_key_exists('labour_minutes', $data) && $data['labour_minutes'] !== null
                ? (int) $data['labour_minutes']
                : BigDecimal::of($version->labour_minutes)->multipliedBy($goodBase)->dividedBy((string) $version->output_qty_base, 0, RoundingMode::HalfUp)->toInt();

            $report = Report::create([
                'number' => $this->numbers->next(DocumentNumbers::REPORT, $order->company_id, $order->branch_id, $order->location_id),
                'order_id' => $order->id, 'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'location_id' => $order->location_id,
                'ledger' => $order->ledger, 'status' => $order->isValued() ? Report::PENDING : Report::RECORDED,
                'qty_good' => (string) $good, 'qty_good_base' => (string) $goodBase, 'qty_scrap' => (string) $scrap, 'qty_scrap_base' => (string) $scrapBase,
                'scrap_reason' => $scrap->isPositive() ? $data['scrap_reason'] : null, 'labour_minutes' => $minutes, 'note' => $data['note'] ?? null,
                'reported_by' => $by->id, 'reported_at' => CarbonImmutable::now(),
            ]);

            $lineNo = 0;
            $product = OrderOutput::query()->where('order_id', $order->id)->where('kind', OrderOutput::PRODUCT)->firstOrFail();

            if ($goodBase->isPositive()) {
                ReportLine::create(['report_id' => $report->id, 'output_id' => $product->id, 'line_no' => ++$lineNo, 'kind' => ReportLine::PRODUCT, 'item_id' => $order->item_id, 'qty_base' => (string) $goodBase]);
                $this->reported($product, $goodBase);
            }

            foreach ($byProducts as $entry) {
                ReportLine::create(['report_id' => $report->id, 'output_id' => $entry['output']->id, 'line_no' => ++$lineNo, 'kind' => ReportLine::BY_PRODUCT, 'item_id' => $entry['output']->item_id, 'qty_base' => (string) $entry['qty']]);
                $this->reported($entry['output'], $entry['qty']);
            }

            if ($scrapBase->isPositive()) {
                ReportLine::create(['report_id' => $report->id, 'output_id' => null, 'line_no' => ++$lineNo, 'kind' => ReportLine::SCRAP, 'item_id' => $order->item_id, 'qty_base' => (string) $scrapBase]);
            }

            $order->fill([
                'qty_good_base' => (string) Qty::of(BigDecimal::of((string) $order->qty_good_base)->plus($goodBase)),
                'qty_scrap_base' => (string) Qty::of(BigDecimal::of((string) $order->qty_scrap_base)->plus($scrapBase)),
            ])->save();
            $this->materials->start($order);

            if ($order->isValued()) {
                $this->costing->costPending($order->id);
            }

            event(new ProductionReported(...$this->orders->eventArgs($order->refresh(), $report->id)));

            return $report->refresh();
        });
    }

    private function quantity(mixed $value, string $field, string $uomId): BigDecimal
    {
        try {
            $qty = BigDecimal::of((string) $value);
        } catch (MathException) {
            throw Errors::make(422, 'qty_invalid', field: $field);
        }

        if ($qty->isNegative()) {
            throw Errors::make(422, 'qty_invalid', field: $field);
        }

        if (! $this->units->fitsPrecision($uomId, $qty)) {
            throw Errors::make(422, 'qty_precision', field: $field);
        }

        return Qty::of($qty);
    }

    /**
     * @param  list<array{output_id: string, qty: string}>  $lines
     * @return list<array{output: OrderOutput, qty: BigDecimal}>
     */
    private function byProducts(Order $order, array $lines): array
    {
        $outputs = OrderOutput::query()->where('order_id', $order->id)->where('kind', OrderOutput::BY_PRODUCT)->get()->keyBy('id');
        $resolved = [];

        foreach (array_values($lines) as $index => $line) {
            $output = $outputs->get((string) ($line['output_id'] ?? '')) ?? throw Errors::make(422, 'output_unknown', field: "by_products.{$index}.output_id");
            $qty = $this->quantity($line['qty'] ?? null, "by_products.{$index}.qty", (string) Item::query()->whereKey($output->item_id)->value('base_uom_id'));

            if ($qty->isPositive()) {
                $resolved[] = ['output' => $output, 'qty' => $qty];
            }
        }

        return $resolved;
    }

    private function reported(OrderOutput $output, BigDecimal $qty): void
    {
        $output->update(['qty_reported_base' => (string) Qty::of(BigDecimal::of((string) $output->qty_reported_base)->plus($qty))]);
    }
}
