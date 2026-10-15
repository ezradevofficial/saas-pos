<?php

namespace Modules\FixedAssets\Http\Resources;

use Illuminate\Http\Request;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetDocument;
use Modules\FixedAssets\Models\AssetEvent;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\DepreciationLine;
use Modules\FixedAssets\Models\DepreciationRun;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Models\MaintenanceSchedule;
use Modules\FixedAssets\Models\Reading;
use Modules\FixedAssets\Models\Revaluation;
use Modules\FixedAssets\Models\Transfer;
use Modules\FixedAssets\Models\WorkOrder;
use Modules\FixedAssets\Support\Access;
use Modules\FixedAssets\Support\Decimals;

/**
 * FA-26: how the module's records other than assets are shown. Money is
 * null without `fixed_assets.value.view` at the asset's location (or the
 * run's company), RBAC-05.
 */
final class RecordResources
{
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'company' => Refs::of($category->company),
            'code' => $category->code,
            'name' => $category->name,
            'method' => $category->method,
            'useful_life_months' => $category->useful_life_months,
            'residual_percent' => (string) $category->residual_percent,
            'declining_rate' => $category->declining_rate === null ? null : (string) $category->declining_rate,
            'tax_class' => $category->tax_class,
            'archived_at' => $category->archived_at?->toIso8601String(),
        ];
    }

    public static function itemCategory(ItemCategory $row): array
    {
        return [
            'id' => $row->id,
            'company' => Refs::of($row->company),
            'item' => $row->item === null ? null : ['id' => $row->item->id, 'code' => (string) $row->item->code, 'name' => (string) $row->item->name, 'type' => (string) $row->item->type],
            'category' => Refs::category($row->category),
            'archived_at' => $row->archived_at?->toIso8601String(),
        ];
    }

    public static function run(Request $request, DepreciationRun $run, bool $detail = false): array
    {
        $values = Values::atCompany($request, $run->company_id);

        return [
            'id' => $run->id,
            'company' => Refs::of($run->company),
            'period' => $run->period->format('Y-m'),
            'period_end' => $run->period_end->toDateString(),
            'status' => $run->status,
            'convention' => $run->convention,
            'total' => Values::money($values, $run->total_minor, $run->base_currency),
            'assets_count' => $run->assets_count,
            'posted_by' => Refs::of($run->poster),
            'posted_at' => $run->posted_at?->toIso8601String(),
            'reversed_by' => Refs::of($run->reverser),
            'reversed_at' => $run->reversed_at?->toIso8601String(),
            'reverse_reason' => $run->reverse_reason,
            ...($detail ? ['lines' => $run->lines()->with(['asset', 'category', 'branch', 'costCentre'])->orderBy('from_date')->get()
                ->sortBy(fn (DepreciationLine $line) => $line->asset?->number)->values()
                ->map(fn (DepreciationLine $line) => self::line($line, $values, (string) $run->base_currency))->all()] : []),
        ];
    }

    public static function line(DepreciationLine $line, bool $values, string $currency): array
    {
        return [
            'id' => $line->id,
            'asset' => Refs::asset($line->asset),
            'category' => Refs::category($line->category),
            'branch' => Refs::of($line->branch),
            'cost_centre' => Refs::of($line->costCentre),
            'from' => $line->from_date->toDateString(),
            'to' => $line->to_date->toDateString(),
            'units' => $line->units === null ? null : Decimals::show((string) $line->units),
            'amount' => Values::money($values, $line->amount_minor, $currency),
            'accumulated' => Values::money($values, $line->accumulated_after_minor, $currency),
            'book_value' => Values::money($values, $line->book_value_after_minor, $currency),
        ];
    }

    public static function transfer(Request $request, Transfer $transfer): array
    {
        $asset = $transfer->asset;

        return [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'status' => $transfer->status,
            'asset' => Refs::asset($asset),
            'company' => Refs::of($transfer->company),
            'from_branch' => Refs::of($transfer->fromBranch),
            'from_location' => Refs::of($transfer->fromLocation),
            'to_branch' => Refs::of($transfer->toBranch),
            'to_location' => Refs::of($transfer->toLocation),
            'from_custodian' => Refs::custodian($request, $transfer->fromCustodianUser, $transfer->from_custodian_employee_id),
            'to_custodian' => Refs::custodian($request, $transfer->toCustodianUser, $transfer->to_custodian_employee_id),
            'from_cost_centre' => Refs::of($transfer->fromCostCentre),
            'to_cost_centre' => Refs::of($transfer->toCostCentre),
            'transfer_date' => $transfer->transfer_date->toDateString(),
            'reason' => $transfer->reason,
            'requested_by' => Refs::of($transfer->requester),
            'decided_by' => Refs::of($transfer->decider),
            'decided_at' => $transfer->decided_at?->toIso8601String(),
            'cancel_reason' => $transfer->cancel_reason,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'can' => ['cancel' => $transfer->status === 'pending' && $asset !== null && self::may($request, 'fixed_assets.transfer.request', $asset)],
        ];
    }

    public static function disposal(Request $request, Disposal $disposal): array
    {
        $asset = $disposal->asset;
        $values = $asset !== null && Values::at($request, $asset->location_id);
        $base = (string) ($asset?->base_currency ?? $disposal->currency);
        $money = fn ($minor) => Values::money($values, $minor, $base);

        return [
            'id' => $disposal->id,
            'number' => $disposal->number,
            'status' => $disposal->status,
            'kind' => $disposal->kind,
            'asset' => Refs::asset($asset),
            'company' => Refs::of($disposal->company),
            'disposal_date' => $disposal->disposal_date->toDateString(),
            'proceeds' => Values::money($values, $disposal->proceeds_minor, $disposal->currency),
            'fx' => $values ? $disposal->fx?->jsonSerialize() : null,
            'base_proceeds' => $money($disposal->base_proceeds_minor),
            'party' => Refs::of($disposal->party),
            'reason' => $disposal->reason,
            'depreciation' => $money($disposal->depreciation_minor),
            'cost' => $money($disposal->cost_minor),
            'accumulated' => $money($disposal->accumulated_minor),
            'impairment' => $money($disposal->impairment_minor),
            'book_value' => $money($disposal->book_value_minor),
            'gain' => $money($disposal->gain_minor),
            'surplus' => $money($disposal->surplus_minor),
            'post_error' => $disposal->post_error,
            'post_error_message' => $disposal->post_error === null ? null : __('fixed_assets.errors.'.$disposal->post_error, ['date' => $disposal->disposal_date->toDateString(), 'closed' => '', 'pair' => '', 'month' => '']),
            'requested_by' => Refs::of($disposal->requester),
            'decided_by' => Refs::of($disposal->decider),
            'decided_at' => $disposal->decided_at?->toIso8601String(),
            'posted_by' => Refs::of($disposal->poster),
            'posted_at' => $disposal->posted_at?->toIso8601String(),
            'cancel_reason' => $disposal->cancel_reason,
            'created_at' => $disposal->created_at?->toIso8601String(),
            'can' => [
                'cancel' => in_array($disposal->status, ['pending', 'approved'], true) && $asset !== null && self::may($request, 'fixed_assets.disposal.request', $asset),
                'post' => $disposal->status === 'approved' && $asset !== null && self::may($request, 'fixed_assets.disposal.request', $asset),
            ],
        ];
    }

    public static function revaluation(Request $request, Revaluation $revaluation): array
    {
        $asset = $revaluation->asset;
        $values = $asset !== null && Values::at($request, $asset->location_id);
        $base = (string) ($asset?->base_currency ?? '');

        return [
            'id' => $revaluation->id,
            'kind' => $revaluation->kind,
            'asset' => Refs::asset($asset),
            'revalued_on' => $revaluation->revalued_on->toDateString(),
            'value' => Values::money($values, $revaluation->value_minor, $base),
            'before' => $values ? $revaluation->before : null,
            'after' => $values ? $revaluation->after : null,
            'surplus_change' => Values::money($values, $revaluation->surplus_change_minor, $base),
            'pnl_change' => Values::money($values, $revaluation->pnl_change_minor, $base),
            'reason' => $revaluation->reason,
            'posted_by' => Refs::of($revaluation->poster),
            'created_at' => $revaluation->created_at?->toIso8601String(),
        ];
    }

    public static function schedule(Request $request, MaintenanceSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'asset' => Refs::asset($schedule->asset),
            'name' => $schedule->name,
            'basis' => $schedule->basis,
            'interval_days' => $schedule->interval_days,
            'interval_units' => $schedule->interval_units === null ? null : (string) $schedule->interval_units,
            'warning_days' => (int) $schedule->warning_days,
            'last_done_on' => Refs::date($schedule->last_done_on),
            'last_done_reading' => $schedule->last_done_reading === null ? null : (string) $schedule->last_done_reading,
            'next_due_on' => Refs::date($schedule->next_due_on),
            'next_due_reading' => $schedule->next_due_reading === null ? null : (string) $schedule->next_due_reading,
            'archived_at' => $schedule->archived_at?->toIso8601String(),
        ];
    }

    public static function workOrder(Request $request, WorkOrder $order): array
    {
        $asset = $order->asset;
        $values = $asset !== null && Values::at($request, $asset->location_id);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'asset' => Refs::asset($asset),
            'schedule' => Refs::of($order->schedule),
            'title' => $order->title,
            'description' => $order->description,
            'status' => $order->status,
            'due_on' => Refs::date($order->due_on),
            'assigned_to' => Refs::of($order->assignee),
            'vendor' => Refs::of($order->vendor),
            'completed_on' => Refs::date($order->completed_on),
            'reading' => $order->reading === null ? null : (string) $order->reading,
            'cost' => Values::money($values, $order->cost_minor, $order->currency),
            'base_cost' => Values::money($values, $order->base_cost_minor, $asset?->base_currency ?? $order->currency),
            'created_by' => Refs::of($order->creator),
            'created_at' => $order->created_at?->toIso8601String(),
            'can' => ['edit' => ! in_array($order->status, ['done', 'cancelled'], true) && $asset !== null && self::may($request, 'fixed_assets.maintenance.manage', $asset)],
        ];
    }

    public static function document(AssetDocument $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'mime' => $document->mime,
            'size' => $document->size,
            'uploaded_by' => Refs::of($document->uploader),
            'created_at' => $document->created_at?->toIso8601String(),
            'archived_at' => $document->archived_at?->toIso8601String(),
        ];
    }

    public static function reading(Reading $reading): array
    {
        return [
            'id' => $reading->id,
            'read_on' => $reading->read_on->toDateString(),
            'reading' => (string) $reading->reading,
            'note' => $reading->note,
            'recorded_by' => Refs::of($reading->recorder),
        ];
    }

    public static function event(Request $request, AssetEvent $event, bool $values): array
    {
        $money = ['cost', 'accumulated', 'impairment', 'surplus', 'book_value', 'proceeds', 'gain', 'residual_minor'];
        $hide = fn (?array $data) => $data === null || $values ? $data : array_diff_key($data, array_flip($money));

        return [
            'id' => $event->id,
            'kind' => $event->kind,
            'happened_on' => $event->happened_on->toDateString(),
            'before' => $hide($event->before),
            'after' => $hide($event->after),
            'document_type' => $event->document_type,
            'document_id' => $event->document_id,
            'note' => $event->note,
            'user' => Refs::of($event->user),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private static function may(Request $request, string $permission, Asset $asset): bool
    {
        $user = $request->user();

        return $user !== null && app(Access::class)->at($user, $permission, $asset);
    }
}
