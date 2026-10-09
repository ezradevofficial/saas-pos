<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SalePayment;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;

/**
 * POS-04: a shift's cash per currency. Expected = opening float + cash
 * tendered on its completed sales − change given + applied pay-ins −
 * applied pay-outs − cash paid out by its applied refunds. Held and
 * rejected records never count.
 *
 * Computed on write: at close, and again whenever something lands on a
 * shift already closed (a late sale, refund, void or movement, or a held
 * one approved later), so a closed shift's expected cash and variance are
 * always current. Counted cash never changes after close.
 */
class ShiftCash
{
    public function __construct(private readonly Auditor $auditor) {}

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

        CashMovement::query()->where('shift_id', $shift->id)->where('status', Records::APPLIED)
            ->selectRaw("currency, sum(case when kind = 'pay_in' then amount_minor else -amount_minor end) as total")->groupBy('currency')->get()
            ->each(fn ($row) => $add($row->currency, $row->total));

        RefundPayment::query()->where('method_type', 'cash')
            ->whereIn('refund_id', Refund::query()->where('shift_id', $shift->id)->where('status', Records::APPLIED)->select('id'))
            ->selectRaw('currency, sum(amount_minor) as total')->groupBy('currency')->get()
            ->each(fn ($row) => $add($row->currency, '-'.$row->total));

        return $totals;
    }

    /**
     * Expected and variance per currency from $counted (or, when null, the
     * counted cash already stored). Missing currencies count as zero.
     *
     * @param  array<string, BigInteger>|null  $counted
     */
    public function settle(Shift $shift, ?array $counted = null): void
    {
        $counted ??= ShiftBalance::query()->where('shift_id', $shift->id)->whereNotNull('counted_minor')->get()
            ->mapWithKeys(fn (ShiftBalance $b) => [$b->currency => BigInteger::of((string) $b->counted_minor)])->all();
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
    }

    /** H4: something landed on a closed shift: its expected cash and variance follow, audited. */
    public function recountIfClosed(string $shiftId, string $cause, string $recordId): void
    {
        $shift = Shift::query()->whereKey($shiftId)->lockForUpdate()->first();

        if ($shift === null || $shift->isOpen()) {
            return;
        }

        $before = $this->balances($shift);
        $this->settle($shift);

        $this->auditor->record('pos.shift.recount', $shift, ['balances' => $before], [
            'balances' => $this->balances($shift),
            'cause' => $cause,
            'record_id' => $recordId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function balances(Shift $shift): array
    {
        return DB::table('pos_shift_balances')->where('shift_id', $shift->id)->orderBy('currency')
            ->get(['currency', 'opening_minor', 'counted_minor', 'expected_minor', 'variance_minor'])
            ->map(fn ($row) => (array) $row)->all();
    }
}
