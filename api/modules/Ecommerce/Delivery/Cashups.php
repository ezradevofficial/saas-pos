<?php

namespace Modules\Ecommerce\Delivery;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Ecommerce\Events\EcommercePosting;
use Modules\Ecommerce\Integrations\Postings;
use Modules\Ecommerce\Models\Cashup;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Orders\Returns;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-30: a rider's cash on delivery, reconciled. Pending: the rider's
 * delivered cash on delivery orders not cashed up yet, per currency,
 * expected at what they were due. Closing a cash-up takes all of them in one currency under the
 * rider's row lock (so two cash-ups never take the same delivery), the
 * cash counted, and keeps expected, counted and variance; it is final and
 * audited, and posts `ecommerce.cod_cashup` (EcommercePosting) for
 * Accounting.
 *
 * Review L5: cash a rider collected for an exchange's difference joins
 * their cash-up the same way (it waits in COD clearing until then).
 */
final class Cashups
{
    public function __construct(private readonly Postings $postings) {}

    /** @return list<array{rider_id: string, currency: string, deliveries: int, expected_minor: string}> */
    public function pending(array $riderIds): array
    {
        $rows = collect($this->deliveriesPending($riderIds));

        foreach ($this->exchangeCash($riderIds) as $return) {
            $rider = Rider::query()->where('company_id', $return->company_id)->where('user_id', $return->collected_by)->value('id');
            $key = $rows->search(fn (array $row) => $row['rider_id'] === $rider && $row['currency'] === $return->currency);
            $row = $key === false ? ['rider_id' => $rider, 'currency' => $return->currency, 'deliveries' => 0, 'expected_minor' => '0'] : $rows[$key];
            $row['expected_minor'] = (string) ((int) $row['expected_minor'] + $return->collect_minor);
            $key === false ? $rows->push($row) : $rows->put($key, $row);
        }

        return $rows->values()->all();
    }

    /** @return Collection<int, OrderReturn> exchange differences in cash taken by these riders, not cashed up */
    private function exchangeCash(array $riderIds, ?string $currency = null)
    {
        $users = Rider::query()->whereKey($riderIds)->pluck('user_id');

        return OrderReturn::query()->where('collect_status', 'collected')->whereNull('cashup_id')->whereIn('collected_by', $users)
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))->get()->filter(fn (OrderReturn $r) => Returns::riderCash($r))->values();
    }

    /** @return list<array{rider_id: string, currency: string, deliveries: int, expected_minor: string}> */
    private function deliveriesPending(array $riderIds): array
    {
        return Delivery::query()->whereIn('rider_id', $riderIds)->where('status', Delivery::DELIVERED)
            ->whereNotNull('cod_collected_minor')->where('cod_due_minor', '>', 0)->whereNull('cashup_id')
            // Decision 11: only cash the rider took (staff-confirmed cash is the staff member's).
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ecom_riders')->whereColumn('ecom_riders.id', 'ecom_deliveries.rider_id')->whereColumn('ecom_riders.user_id', 'ecom_deliveries.collected_by'))
            ->groupBy('rider_id', 'currency')->orderBy('rider_id')->orderBy('currency')
            ->selectRaw('rider_id, currency, count(*) as deliveries, sum(cod_due_minor) as expected')->get()
            ->map(fn ($row) => ['rider_id' => $row->rider_id, 'currency' => $row->currency, 'deliveries' => (int) $row->deliveries, 'expected_minor' => (string) $row->expected])
            ->values()->all();
    }

    public function close(Rider $rider, string $currency, int $counted, ?string $note, User $by): Cashup
    {
        return Tx::run(function () use ($rider, $currency, $counted, $note, $by) {
            Rider::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $deliveries = Delivery::query()->where('rider_id', $rider->id)->where('status', Delivery::DELIVERED)->where('currency', $currency)
                ->whereNotNull('cod_collected_minor')->where('cod_due_minor', '>', 0)->whereNull('cashup_id')->where('collected_by', $rider->user_id)->lockForUpdate()->get();

            $exchanges = $this->exchangeCash([$rider->id], $currency)->filter(fn (OrderReturn $r) => $r->company_id === $rider->company_id);

            if ($deliveries->isEmpty() && $exchanges->isEmpty()) {
                throw EcomError::make('cashup_nothing_pending');
            }

            // Expected is what the orders were due (their journals cleared that much into COD clearing); what a rider
            // said they collected only flags the delivery (cod_short, cod_over). Exchange cash adds what was collected.
            $expected = (int) $deliveries->sum('cod_due_minor') + (int) $exchanges->sum('collect_minor');
            $cashup = Cashup::create([
                'company_id' => $rider->company_id,
                'rider_id' => $rider->id,
                'currency' => $currency,
                'expected_minor' => $expected,
                'counted_minor' => $counted,
                'variance_minor' => $counted - $expected,
                'deliveries' => $deliveries->count() + $exchanges->count(),
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'closed_by' => $by->id,
                'closed_at' => CarbonImmutable::now(),
            ]);

            Delivery::query()->whereKey($deliveries->modelKeys())->update(['cashup_id' => $cashup->id, 'updated_at' => now()]);
            OrderReturn::query()->whereKey($exchanges->modelKeys())->get()->each(fn (OrderReturn $r) => $r->forceFill(['cashup_id' => $cashup->id])->save());
            Payment::query()->whereIn('order_id', $deliveries->pluck('order_id'))->where('kind', 'cod')->where('status', 'collected')->update(['cashup_id' => $cashup->id, 'updated_at' => now()]);
            $this->postings->cashup($cashup);

            return $cashup;
        });
    }

    /** The posting type a cash-up raises (for callers that show its journal). */
    public static function postingType(): string
    {
        return EcommercePosting::CASHUP;
    }
}
