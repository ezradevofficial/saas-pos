<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\ShiftClosed;
use Modules\POS\Events\ShiftOpened;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;

/**
 * POS-04: shifts uploaded by a till, idempotent by the device's id. A
 * shift is sent when opened (with its float per currency) and again when
 * closed (with the cash counted per currency); one upload may carry both.
 * Opening needs `pos.shift.open`; closing one's own shift
 * `pos.shift.close`, another cashier's `pos.shift.manage`. One shift is
 * open per device at a time.
 *
 * Expected cash per currency at close = opening float + cash tendered on
 * the shift's completed sales − change given + pay-ins − pay-outs − cash
 * refunded on the shift; variance = counted − expected.
 */
class ShiftUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
        private readonly ShiftCash $cash,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $shifts): array
    {
        return array_map(function (array $data) use ($place) {
            try {
                return UploadResults::shift(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->one($place, $data)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException $e) {
                $shift = Shift::query()->find($data['id']);

                return match (true) {
                    $shift !== null && $shift->device_id === $place->device->id => UploadResults::shift($shift),
                    // Another open shift committed meanwhile: retry once it is closed.
                    str_contains($e->getMessage(), 'pos_shifts_one_open_per_device') => UploadResults::rejected($data['id'], new Rejection('shift_already_open', 'id', retryable: true)),
                    // The id is taken (another device or tenant): never retry it.
                    default => UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id')),
                };
            }
        }, $shifts);
    }

    private function one(DevicePlace $place, array $data): Shift
    {
        $shift = Shift::query()->whereKey($data['id'])->lockForUpdate()->first();

        if ($shift !== null && $shift->device_id !== $place->device->id) {
            throw new Rejection('id_conflict', 'id');
        }

        $closing = $data['closing'] ?? null;

        if ($shift === null) {
            return $this->open($place, $data, $closing);
        }

        if ($closing !== null && $shift->isOpen()) {
            $closer = $this->closer($place, $shift->opened_by, $closing);
            $shift->forceFill(['status' => Shift::CLOSED, 'closed_by' => $closer->id, 'closed_at' => CarbonImmutable::parse($closing['closed_at'])->utc()])->save();
            $this->settle($shift, $closer, $closing);
        }

        return $shift;
    }

    /** A new shift; one sent already closed is stored closed (it never holds the device's one open slot). */
    private function open(DevicePlace $place, array $data, ?array $closing): Shift
    {
        $opener = $this->authority->user($data['opened_by_id'], 'opened_by_id');

        if (! $this->authority->can($opener, 'pos.shift.open', $place->scope())) {
            throw new Rejection('not_permitted', 'opened_by_id');
        }

        $closer = $closing === null ? null : $this->closer($place, $opener->id, $closing);

        if ($closer === null && Shift::query()->where('device_id', $place->device->id)->where('status', Shift::OPEN)->exists()) {
            throw new Rejection('shift_already_open', 'id', retryable: true);
        }

        $openedAt = CarbonImmutable::parse($data['opened_at'])->utc();
        $shift = Shift::create([
            'id' => $data['id'],
            ...$place->columns(),
            'status' => $closer === null ? Shift::OPEN : Shift::CLOSED,
            'opened_by' => $opener->id,
            'opened_at' => $openedAt,
            'closed_by' => $closer?->id,
            'closed_at' => $closer === null ? null : CarbonImmutable::parse($closing['closed_at'])->utc(),
            'received_at' => now(),
        ]);

        foreach ($data['opening_float'] ?? [] as $index => $float) {
            $this->currency($float['currency'], "opening_float.{$index}.currency");
            ShiftBalance::create(['shift_id' => $shift->id, 'currency' => $float['currency'], 'opening_minor' => (string) $float['amount_minor']]);
        }

        $this->auditor->record('pos.shift.open', $shift, null, [
            'opening_float' => $this->balances($shift, 'opening_minor'),
        ], ['user_id' => $opener->id, 'device_time' => $openedAt]);
        ShiftOpened::dispatch($this->tenants->require(), $shift->id);

        if ($closer !== null) {
            $this->settle($shift, $closer, $closing);
        }

        return $shift;
    }

    /** Who closes: the opener with `pos.shift.close`, anyone else with `pos.shift.manage`. */
    private function closer(DevicePlace $place, string $openedBy, array $closing): User
    {
        $closer = $this->authority->user($closing['closed_by_id'], 'closing.closed_by_id');
        $permission = $closer->id === $openedBy ? 'pos.shift.close' : 'pos.shift.manage';

        if (! $this->authority->can($closer, $permission, $place->scope())) {
            throw new Rejection('not_permitted', 'closing.closed_by_id');
        }

        return $closer;
    }

    /** The cash-up: expected, counted and variance per currency; the shift closed, audited and announced. */
    private function settle(Shift $shift, User $closer, array $closing): void
    {
        $closedAt = CarbonImmutable::parse($closing['closed_at'])->utc();
        $counted = [];

        foreach ($closing['counted'] ?? [] as $index => $count) {
            $this->currency($count['currency'], "closing.counted.{$index}.currency");
            $counted[$count['currency']] = BigInteger::of((string) $count['amount_minor']);
        }

        $this->cash->settle($shift, $counted);

        $shift->forceFill([
            'status' => Shift::CLOSED,
            'closed_by' => $closer->id,
            'closed_at' => $closedAt,
            'note' => $closing['note'] ?? null,
            'closed_received_at' => now(),
        ])->save();

        $this->auditor->record('pos.shift.close', $shift, ['status' => Shift::OPEN], [
            'status' => Shift::CLOSED,
            'balances' => $shift->balances()->get(['currency', 'opening_minor', 'counted_minor', 'expected_minor', 'variance_minor'])->toArray(),
        ], ['user_id' => $closer->id, 'device_time' => $closedAt]);
        ShiftClosed::dispatch($this->tenants->require(), $shift->id);
    }

    private function currency(string $code, string $field): void
    {
        if (! TenantCurrency::query()->where('code', $code)->exists()) {
            throw new Rejection('currency_unknown', $field);
        }
    }

    /** @return array<string, string> */
    private function balances(Shift $shift, string $column): array
    {
        return ShiftBalance::query()->where('shift_id', $shift->id)->orderBy('currency')->pluck($column, 'currency')->map(fn ($v) => (string) $v)->all();
    }
}
