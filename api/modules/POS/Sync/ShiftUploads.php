<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\ShiftClosed;
use Modules\POS\Events\ShiftOpened;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SalePayment;
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
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $shifts): array
    {
        return array_map(function (array $data) use ($place) {
            try {
                return UploadResults::shift(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->one($place, $data)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException) {
                $shift = Shift::query()->find($data['id']);

                return $shift !== null && $shift->device_id === $place->device->id
                    ? UploadResults::shift($shift)
                    : UploadResults::rejected($data['id'], new Rejection('shift_already_open', 'id', retryable: true));
            }
        }, $shifts);
    }

    private function one(DevicePlace $place, array $data): Shift
    {
        $shift = Shift::query()->whereKey($data['id'])->lockForUpdate()->first();

        if ($shift !== null && $shift->device_id !== $place->device->id) {
            throw new Rejection('id_conflict', 'id');
        }

        $shift ??= $this->open($place, $data);

        if (($data['closing'] ?? null) !== null && $shift->isOpen()) {
            $this->close($place, $shift, $data['closing']);
        }

        return $shift;
    }

    private function open(DevicePlace $place, array $data): Shift
    {
        $opener = $this->authority->user($data['opened_by_id'], 'opened_by_id');

        if (! $this->authority->can($opener, 'pos.shift.open', $place->scope())) {
            throw new Rejection('not_permitted', 'opened_by_id');
        }

        $closing = ($data['closing'] ?? null) !== null;

        if (! $closing && Shift::query()->where('device_id', $place->device->id)->where('status', Shift::OPEN)->exists()) {
            throw new Rejection('shift_already_open', 'id', retryable: true);
        }

        $openedAt = CarbonImmutable::parse($data['opened_at'])->utc();
        $shift = Shift::create([
            'id' => $data['id'],
            ...$place->columns(),
            'status' => Shift::OPEN,
            'opened_by' => $opener->id,
            'opened_at' => $openedAt,
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

        return $shift;
    }

    private function close(DevicePlace $place, Shift $shift, array $closing): void
    {
        $closer = $this->authority->user($closing['closed_by_id'], 'closing.closed_by_id');
        $permission = $closer->id === $shift->opened_by ? 'pos.shift.close' : 'pos.shift.manage';

        if (! $this->authority->can($closer, $permission, $place->scope())) {
            throw new Rejection('not_permitted', 'closing.closed_by_id');
        }

        $closedAt = CarbonImmutable::parse($closing['closed_at'])->utc();
        $counted = [];

        foreach ($closing['counted'] ?? [] as $index => $count) {
            $this->currency($count['currency'], "closing.counted.{$index}.currency");
            $counted[$count['currency']] = BigInteger::of((string) $count['amount_minor']);
        }

        $expected = $this->expected($shift);
        $currencies = array_unique([...array_keys($expected), ...array_keys($counted)]);
        sort($currencies);

        foreach ($currencies as $currency) {
            $balance = ShiftBalance::query()->firstOrNew(['shift_id' => $shift->id, 'currency' => $currency], ['opening_minor' => 0]);
            $expect = $expected[$currency] ?? BigInteger::zero();
            $count = $counted[$currency] ?? BigInteger::zero();
            $balance->fill([
                'expected_minor' => (string) $expect,
                'counted_minor' => (string) $count,
                'variance_minor' => (string) $count->minus($expect),
            ])->save();
        }

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

    /** @return array<string, BigInteger> expected cash per currency (minor units) */
    public function expected(Shift $shift): array
    {
        $totals = [];
        $add = function (string $currency, string|int $minor) use (&$totals) {
            $totals[$currency] = ($totals[$currency] ?? BigInteger::zero())->plus((string) $minor);
        };

        foreach (ShiftBalance::query()->where('shift_id', $shift->id)->get() as $balance) {
            $add($balance->currency, $balance->opening_minor);
        }

        $sales = Sale::query()->where('shift_id', $shift->id)->where('status', Sale::COMPLETED);

        SalePayment::query()->whereIn('sale_id', (clone $sales)->select('id'))->where('method_type', 'cash')
            ->selectRaw('currency, sum(amount_minor) as total')->groupBy('currency')->get()
            ->each(fn ($row) => $add($row->currency, $row->total));

        (clone $sales)->where('change_minor', '>', 0)
            ->selectRaw('change_currency, sum(change_minor) as total')->groupBy('change_currency')->get()
            ->each(fn ($row) => $add($row->change_currency, '-'.$row->total));

        CashMovement::query()->where('shift_id', $shift->id)
            ->selectRaw("currency, sum(case when kind = 'pay_in' then amount_minor else -amount_minor end) as total")->groupBy('currency')->get()
            ->each(fn ($row) => $add($row->currency, $row->total));

        RefundPayment::query()->where('method_type', 'cash')
            ->whereIn('refund_id', DB::table('pos_refunds')->where('shift_id', $shift->id)->select('id'))
            ->selectRaw('currency, sum(amount_minor) as total')->groupBy('currency')->get()
            ->each(fn ($row) => $add($row->currency, '-'.$row->total));

        return $totals;
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
