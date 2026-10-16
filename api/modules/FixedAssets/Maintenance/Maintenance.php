<?php

namespace Modules\FixedAssets\Maintenance;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\Assets\Assets;
use Modules\FixedAssets\Depreciation\Spans;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\MaintenanceSchedule;
use Modules\FixedAssets\Models\WorkOrder;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\FixedAssets\Support\Numbers;
use Modules\FixedAssets\Support\Rates;

/**
 * FA-19: maintenance schedules (by time or by usage) and work orders. A
 * schedule's next due date is its last done date (else the asset's
 * in-service date, else today) plus its interval; its next due reading is
 * its last done reading (else the meter at acquisition) plus its interval.
 * Completing a work order of a schedule moves the schedule on, records the
 * reading taken, and keeps the cost with its base amount. Costs post
 * nothing (decision 7).
 */
class Maintenance
{
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public function __construct(
        private readonly Numbers $numbers,
        private readonly Rates $rates,
        private readonly History $history,
        private readonly Assets $assets,
    ) {}

    /** @param array<string, mixed> $data */
    public function schedule(Asset $asset, array $data): MaintenanceSchedule
    {
        if ($asset->status === Asset::DISPOSED || $asset->archived_at !== null) {
            throw FaError::make('asset_not_open', status: 409);
        }

        $schedule = new MaintenanceSchedule(['asset_id' => $asset->id]);

        return $this->saveSchedule($schedule, $asset, $data);
    }

    /** @param array<string, mixed> $data */
    public function updateSchedule(MaintenanceSchedule $schedule, array $data): MaintenanceSchedule
    {
        return $this->saveSchedule($schedule, Asset::query()->findOrFail($schedule->asset_id), $data);
    }

    public function archiveSchedule(MaintenanceSchedule $schedule): MaintenanceSchedule
    {
        $schedule->fill(['archived_at' => CarbonImmutable::now()])->save();

        return $schedule->refresh();
    }

    /** @param array<string, mixed> $data */
    public function createOrder(Asset $asset, array $data, User $by): WorkOrder
    {
        if ($asset->status === Asset::DISPOSED || $asset->archived_at !== null) {
            throw FaError::make('asset_not_open', status: 409);
        }

        return FaError::transaction(function () use ($asset, $data, $by) {
            $order = new WorkOrder([
                'number' => $this->numbers->next(Numbers::WORK_ORDER, $asset->company_id, $asset->branch_id, $asset->location_id),
                'company_id' => $asset->company_id,
                'asset_id' => $asset->id,
                'status' => 'open',
                'created_by' => $by->id,
            ]);

            return $this->saveOrder($order, $asset, $data, $by);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateOrder(WorkOrder $order, array $data, User $by): WorkOrder
    {
        return FaError::transaction(function () use ($order, $data, $by) {
            $order = WorkOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($order->status, ['done', 'cancelled'], true)) {
                throw FaError::make('document_not_open', status: 409);
            }

            return $this->saveOrder($order, Asset::query()->findOrFail($order->asset_id), $data, $by);
        });
    }

    /** @param array<string, mixed> $data */
    private function saveSchedule(MaintenanceSchedule $schedule, Asset $asset, array $data): MaintenanceSchedule
    {
        $schedule->fill(array_intersect_key($data, array_flip(['name', 'basis', 'interval_days', 'interval_units', 'warning_days', 'last_done_on', 'last_done_reading'])));

        if ($schedule->basis === 'time' && $schedule->interval_days === null) {
            throw FaError::make('schedule_interval', field: 'interval_days');
        }

        if ($schedule->basis === 'usage' && $schedule->interval_units === null) {
            throw FaError::make('schedule_interval', field: 'interval_units');
        }

        $this->dueNext($schedule, $asset);
        $schedule->save();

        return $schedule->refresh();
    }

    /** @param array<string, mixed> $data */
    private function saveOrder(WorkOrder $order, Asset $asset, array $data, User $by): WorkOrder
    {
        $company = Company::query()->findOrFail($asset->company_id);
        $order->fill(array_intersect_key($data, array_flip(['title', 'description', 'due_on', 'assigned_to', 'completed_on', 'reading'])));

        if (array_key_exists('schedule_id', $data)) {
            $schedule = $data['schedule_id'] === null ? null : MaintenanceSchedule::query()->find($data['schedule_id']);

            if ($data['schedule_id'] !== null && ($schedule === null || $schedule->asset_id !== $asset->id)) {
                throw FaError::make('schedule_other_asset', field: 'schedule_id');
            }

            $order->schedule_id = $schedule?->id;
        }

        if (array_key_exists('vendor_id', $data)) {
            $vendor = $data['vendor_id'] === null ? null : Party::query()->find($data['vendor_id']);

            if ($data['vendor_id'] !== null && ($vendor === null || ($vendor->company_id !== null && $vendor->company_id !== $company->id))) {
                throw FaError::make('party_unknown', field: 'vendor_id');
            }

            $order->vendor_id = $vendor?->id;
        }

        if (array_key_exists('cost_minor', $data)) {
            $order->cost_minor = $data['cost_minor'];
            $order->currency = $data['cost_minor'] === null ? null : $this->rates->currency((string) ($data['currency'] ?? $company->base_currency));
        }

        if (isset($data['status'])) {
            $order->status = $data['status'];
        }

        if ($order->status === 'done') {
            $order->completed_on ??= CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString();
        }

        if ($order->cost_minor !== null && $order->currency !== null) {
            $fx = $this->rates->fx($company, (string) $order->currency, $order->completed_on ?? $order->due_on ?? CarbonImmutable::now()->toDateString(), 'currency');
            $order->fx = $fx;
            $order->base_cost_minor = Rates::toBase($fx, (int) $order->cost_minor, (string) $order->currency);
        }

        $order->save();

        if ($order->status === 'done') {
            $this->done($order, $asset, $by);
        }

        return $order->refresh();
    }

    /** A work order completed: its reading kept, its schedule moved on, the asset's history told. */
    private function done(WorkOrder $order, Asset $asset, User $by): void
    {
        $on = $order->completed_on->toDateString();

        if ($order->reading !== null && BigDecimal::of((string) $order->reading)->isGreaterThan(Spans::meter($asset, $order->completed_on))) {
            $this->assets->read($asset, $on, (string) $order->reading, $order->number, $by);
        }

        if ($order->schedule_id !== null) {
            $schedule = MaintenanceSchedule::query()->findOrFail($order->schedule_id);
            $schedule->fill(['last_done_on' => $on, 'last_done_reading' => $order->reading ?? Spans::meter($asset, $order->completed_on), 'reminded_for' => null]);
            $this->dueNext($schedule, $asset);
            $schedule->save();
        }

        $this->history->record($asset, 'maintenance', $on, null, ['work_order' => $order->number, 'title' => $order->title,
            'cost' => $order->base_cost_minor === null ? null : (string) $order->base_cost_minor], 'work_order', $order->id, null, $by);
    }

    private function dueNext(MaintenanceSchedule $schedule, Asset $asset): void
    {
        if ($schedule->basis === 'time') {
            $from = $schedule->last_done_on ?? $asset->in_service_on ?? CarbonImmutable::now();
            $schedule->next_due_on = CarbonImmutable::parse($from)->addDays((int) $schedule->interval_days)->toDateString();
            $schedule->next_due_reading = null;
        } else {
            $from = (string) ($schedule->last_done_reading ?? $asset->meter_start ?? '0');
            $schedule->next_due_reading = (string) BigDecimal::of($from)->plus((string) $schedule->interval_units);
            $schedule->next_due_on = null;
        }
    }
}
