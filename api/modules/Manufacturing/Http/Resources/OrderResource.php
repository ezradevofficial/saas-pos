<?php

namespace Modules\Manufacturing\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\IssueLine;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Models\OrderOutput;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\ReportLine;
use Modules\Manufacturing\Models\ReturnLine;
use Modules\Manufacturing\Support\Places;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-10 to MFG-16: a production order: its product, quantities (planned,
 * good, scrap), status, ledger and dates; its costs for holders of
 * `manufacturing.cost.view` at its location (RBAC-05); in the detail its
 * materials (required, standard for the good output, issued, returned,
 * consumed), outputs, issues, returns, reports and what the reader may do.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $costs = Costs::atLocation($request, $order->location_id) && $order->isValued();
        $money = fn ($minor) => $costs ? Costs::money($minor, $order->currency) : null;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'ledger' => $order->ledger,
            'company' => Refs::named($order->company),
            'branch' => Refs::named($order->branch),
            'location' => Refs::named($order->location),
            'item' => Refs::item($order->item),
            'uom' => Refs::uom($order->uom),
            'bom_id' => $order->bom_id,
            'bom_version_id' => $order->bom_version_id,
            'qty' => Qty::show($order->qty),
            'qty_base' => Qty::show($order->qty_base),
            'qty_good' => Qty::show(self::inUnit($order, $order->qty_good_base)),
            'qty_scrap' => Qty::show(self::inUnit($order, $order->qty_scrap_base)),
            'qty_good_base' => Qty::show($order->qty_good_base),
            'qty_scrap_base' => Qty::show($order->qty_scrap_base),
            'planned_start' => $order->planned_start?->format('Y-m-d'),
            'planned_end' => $order->planned_end?->format('Y-m-d'),
            'note' => $order->note,
            'planned_by' => Refs::user($order->planner),
            'released_by' => Refs::user($order->releaser),
            'released_at' => Refs::at($order->released_at),
            'closed_by' => Refs::user($order->closer),
            'closed_at' => Refs::at($order->closed_at),
            'close_note' => $order->close_note,
            'cancel_reason' => $order->cancel_reason,
            'material_cost' => $money((string) $order->material_cost_minor),
            'labour_cost' => $money((string) $order->labour_cost_minor),
            'overhead_cost' => $money((string) $order->overhead_cost_minor),
            'relieved' => $money((string) $order->relieved_minor),
            'variance' => $order->variance_minor === null ? null : $money((string) $order->variance_minor),
            'custom' => (object) app(CustomFieldPresenter::class)->present($request->user(), OrderEntity::KEY, $order->custom),
            ...($this->detail ? $this->details($order, $request, $costs) : []),
        ];
    }

    /** A base quantity of the order's product in the order's unit (its own factor). */
    public static function inUnit(Order $order, string $base): BigDecimal
    {
        return Qty::of(Qty::ratio($base, (string) $order->qty, (string) $order->qty_base));
    }

    private function details(Order $order, Request $request, bool $costs): array
    {
        $places = app(Places::class);
        $user = $request->user();
        $may = fn (string $permission) => $places->at($user, $permission, $order->location_id);
        $running = in_array($order->status, Order::RUNNING, true);
        $planned = BigDecimal::of((string) $order->qty_base);
        $good = BigDecimal::of((string) $order->qty_good_base);
        $issues = Issue::query()->where('order_id', $order->id)->with(['lines.item', 'lines.uom', 'issuer'])->orderBy('issued_at')->orderBy('id')->get();
        $returns = MaterialReturn::query()->where('order_id', $order->id)->with(['lines.item', 'lines.uom', 'returner'])->orderBy('returned_at')->orderBy('id')->get();
        $reports = Report::query()->where('order_id', $order->id)->with(['lines.item', 'reporter'])->orderBy('reported_at')->orderBy('id')->get();
        $money = fn ($minor) => $costs && $minor !== null ? Costs::money((string) $minor, $order->currency) : null;
        $untouched = $issues->isEmpty() && $reports->isEmpty();

        return [
            'materials' => $order->materials()->with(['item', 'uom'])->get()->map(function (OrderMaterial $material) use ($planned, $good, $money) {
                $standard = $planned->isZero() ? Qty::zero() : Qty::of(Qty::ratio((string) $material->qty_required_base, $good, $planned));
                $consumed = BigDecimal::of((string) $material->qty_issued_base)->minus((string) $material->qty_returned_base);

                return [
                    'id' => $material->id,
                    'line_no' => $material->line_no,
                    'item' => Refs::item($material->item),
                    'uom' => Refs::uom($material->uom),
                    'qty_required' => Qty::show($material->qty_required_base),
                    'expected_loss' => Qty::show($material->expected_loss_base),
                    'qty_standard' => Qty::show($standard),
                    'qty_issued' => Qty::show($material->qty_issued_base),
                    'qty_returned' => Qty::show($material->qty_returned_base),
                    'qty_consumed' => Qty::show($consumed),
                    'qty_open' => Qty::show(BigDecimal::max(BigDecimal::zero(), BigDecimal::of((string) $material->qty_required_base)->minus($consumed))),
                    'value' => $money((string) BigInteger::of((string) $material->value_issued_minor)->minus((string) $material->value_returned_minor)),
                ];
            })->all(),
            'outputs' => $order->outputs()->with('item')->get()->map(fn (OrderOutput $output) => [
                'id' => $output->id,
                'kind' => $output->kind,
                'item' => Refs::item($output->item),
                'qty_planned' => Qty::show($output->qty_planned_base),
                'qty_reported' => Qty::show($output->qty_reported_base),
                'cost_share_percent' => Qty::show($output->cost_share_percent),
            ])->all(),
            'issues' => $issues->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'number' => $issue->number,
                'issued_at' => Refs::at($issue->issued_at),
                'issued_by' => Refs::user($issue->issuer),
                'note' => $issue->note,
                'valued' => $issue->valued_at !== null,
                'lines' => $issue->lines->map(fn (IssueLine $line) => [
                    'id' => $line->id, 'material_id' => $line->material_id, 'item' => Refs::item($line->item), 'uom' => Refs::uom($line->uom),
                    'qty' => Qty::show($line->qty), 'qty_base' => Qty::show($line->qty_base), 'qty_returned_base' => Qty::show($line->qty_returned_base),
                    'value' => $money($line->value_minor),
                ])->all(),
            ])->all(),
            'returns' => $returns->map(fn (MaterialReturn $return) => [
                'id' => $return->id,
                'number' => $return->number,
                'issue_id' => $return->issue_id,
                'reason' => $return->reason,
                'returned_at' => Refs::at($return->returned_at),
                'returned_by' => Refs::user($return->returner),
                'valued' => $return->valued_at !== null,
                'lines' => $return->lines->map(fn (ReturnLine $line) => [
                    'id' => $line->id, 'issue_line_id' => $line->issue_line_id, 'item' => Refs::item($line->item), 'uom' => Refs::uom($line->uom),
                    'qty' => Qty::show($line->qty), 'value' => $money($line->value_minor),
                ])->all(),
                'flags' => $return->flags ?? [],
            ])->all(),
            'reports' => $reports->map(fn (Report $report) => [
                'id' => $report->id,
                'number' => $report->number,
                'status' => $report->status,
                'reported_at' => Refs::at($report->reported_at),
                'reported_by' => Refs::user($report->reporter),
                'qty_good' => Qty::show($report->qty_good),
                'qty_scrap' => Qty::show($report->qty_scrap),
                'scrap_reason' => $report->scrap_reason,
                'labour_minutes' => $report->labour_minutes,
                'note' => $report->note,
                'material_cost' => $money($report->material_cost_minor),
                'labour_cost' => $money($report->labour_cost_minor),
                'overhead_cost' => $money($report->overhead_cost_minor),
                'cost' => $money($report->cost_minor),
                'lines' => $report->lines->map(fn (ReportLine $line) => [
                    'id' => $line->id, 'kind' => $line->kind, 'item' => Refs::item($line->item), 'qty_base' => Qty::show($line->qty_base),
                    'cost' => $money($line->cost_minor),
                    // Review item 7: what Inventory received of it.
                    'value' => $money($line->value_minor),
                ])->all(),
                'flags' => $report->flags ?? [],
            ])->all(),
            'can' => [
                'edit' => $order->status === Order::PLANNED && $may('manufacturing.order.create'),
                'release' => $order->status === Order::PLANNED && $may('manufacturing.order.release'),
                'cancel' => (in_array($order->status, [Order::PLANNED, Order::PENDING_APPROVAL], true) || ($order->status === Order::RELEASED && $untouched))
                    && $may('manufacturing.order.cancel'),
                'close' => $running && $may('manufacturing.order.close'),
                'issue' => $running && $may('manufacturing.material.issue'),
                'return' => $running && $issues->isNotEmpty() && $may('manufacturing.material.return'),
                'report' => $running && $may('manufacturing.production.report'),
                'costs' => $costs,
            ],
        ];
    }
}
