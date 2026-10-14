<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\InvoiceLine;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Support\Pricing;

/**
 * PRC-18: supplier performance per company and supplier over a date range
 * (calendar days in each company's time zone), from what happened:
 *
 * - `orders`: POs submitted with an order date in the range;
 * - `on_time`: share of posted GRN lines (received in the range, of POs
 *   with an expected date) received on or before it, in basis points;
 * - `quality`: accepted ÷ received quantity of those lines, in basis points;
 * - `lead_time_days`: mean days from the PO's approval to each GRN;
 * - `price_variance`: Σ (invoiced − ordered unit price) × invoiced quantity
 *   of approved invoices dated in the range, in base currency at each
 *   invoice's rate, and as basis points of the ordered value.
 *
 * $scope limits the rows to the companies and branches the reader sees.
 */
class SupplierPerformance
{
    /**
     * @param  callable(Builder, string): Builder  $scope  applies the reader's visibility to a query on a branch column
     * @return list<array<string, mixed>>
     */
    public function rows(string $from, string $to, ?string $companyId, ?string $partyId, callable $scope): array
    {
        $companies = Company::query()->when($companyId !== null, fn ($q) => $q->whereKey($companyId))->get()->keyBy('id');
        $rows = [];
        $key = function (string $company, string $party) use (&$rows): string {
            $rows["{$company}|{$party}"] ??= [
                'company_id' => $company, 'party_id' => $party, 'orders' => 0, 'receipts' => [], 'lines' => 0, 'timed' => 0, 'on_time' => 0,
                'received' => BigDecimal::zero(), 'accepted' => BigDecimal::zero(), 'lead' => [], 'variance' => 0, 'ordered' => 0,
            ];

            return "{$company}|{$party}";
        };
        $between = function (Builder $query, string $column) use ($from, $to) {
            $zone = "(select coalesce(companies.timezone, 'UTC') from companies where companies.id = ".$query->qualifyColumn('company_id').')';

            return $query->whereRaw($query->qualifyColumn($column)." >= (?::date)::timestamp at time zone {$zone}", [$from])
                ->whereRaw($query->qualifyColumn($column)." < ((?::date) + 1)::timestamp at time zone {$zone}", [$to]);
        };

        $orders = $scope(Order::query()->whereNotIn('status', [Order::DRAFT, Order::CANCELLED])->whereBetween('order_date', [$from, $to]), 'branch_id')
            ->whereIn('company_id', $companies->keys())->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))->get(['id', 'company_id', 'party_id']);

        foreach ($orders as $order) {
            $k = $key($order->company_id, $order->party_id);
            $rows[$k]['orders']++;
        }

        $receipts = $between($scope(Receipt::query()->where('status', Receipt::POSTED), 'branch_id'), 'received_at')
            ->whereIn('company_id', $companies->keys())->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))
            ->with('order:id,decided_at,expected_on')->get();
        $lines = ReceiptLine::query()->whereIn('receipt_id', $receipts->pluck('id'))->get()->groupBy('receipt_id');

        foreach ($receipts as $receipt) {
            $k = $key($receipt->company_id, $receipt->party_id);
            $entry = &$rows[$k];
            $entry['receipts'][$receipt->id] = true;

            if ($receipt->order?->decided_at !== null) {
                $entry['lead'][] = max(0, $receipt->order->decided_at->diffInSeconds($receipt->received_at, false)) / 86400;
            }

            foreach ($lines->get($receipt->id, collect()) as $line) {
                $entry['lines']++;
                $entry['received'] = $entry['received']->plus((string) $line->received_qty);
                $entry['accepted'] = $entry['accepted']->plus((string) $line->accepted_qty);

                if ($line->on_time !== null) {
                    $entry['timed']++;
                    $entry['on_time'] += $line->on_time ? 1 : 0;
                }
            }

            unset($entry);
        }

        $invoices = $scope(Invoice::query()->where('status', Invoice::APPROVED)->whereBetween('invoice_date', [$from, $to]), 'branch_id')
            ->whereIn('company_id', $companies->keys())->when($partyId !== null, fn ($q) => $q->where('party_id', $partyId))->get();
        $invoiceLines = InvoiceLine::query()->with('orderLine:id,unit_price_minor')->whereIn('invoice_id', $invoices->pluck('id'))->get()->groupBy('invoice_id');

        foreach ($invoices as $invoice) {
            $k = $key($invoice->company_id, $invoice->party_id);
            $entry = &$rows[$k];
            $fx = $invoice->fx ?? FxSnapshot::identity($invoice->currency);

            foreach ($invoiceLines->get($invoice->id, collect()) as $line) {
                $ordered = (string) BigDecimal::of((string) $line->qty)->multipliedBy($line->orderLine->unit_price_minor)->toScale(0, RoundingMode::HalfUp);
                $entry['variance'] += Pricing::convert($fx, $line->price_variance_minor, $invoice->currency);
                $entry['ordered'] += Pricing::convert($fx, $ordered, $invoice->currency);
            }

            unset($entry);
        }

        $parties = Party::query()->whereKey(array_unique(array_column($rows, 'party_id')))->get(['id', 'name'])->keyBy('id');
        $bp = fn (BigDecimal|int $part, BigDecimal|int $whole) => BigDecimal::of($whole)->isZero() ? null
            : BigDecimal::of($part)->multipliedBy(10000)->dividedBy($whole, 0, RoundingMode::HalfUp)->toInt();

        return collect($rows)->map(function (array $entry) use ($parties, $companies, $bp) {
            $currency = (string) $companies->get($entry['company_id'])?->base_currency;

            return [
                'company' => ['id' => $entry['company_id'], 'name' => $companies->get($entry['company_id'])?->name],
                'party' => ['id' => $entry['party_id'], 'name' => $parties->get($entry['party_id'])?->name],
                'orders' => $entry['orders'],
                'receipts' => count($entry['receipts']),
                'lines' => $entry['lines'],
                'on_time_bp' => $bp($entry['on_time'], $entry['timed']),
                'quality_bp' => $bp($entry['accepted'], $entry['received']),
                'lead_time_days' => $entry['lead'] === [] ? null : number_format(array_sum($entry['lead']) / count($entry['lead']), 1, '.', ''),
                'price_variance' => ['amount_minor' => (string) $entry['variance'], 'currency' => $currency],
                'price_variance_bp' => $bp($entry['variance'], $entry['ordered']),
                'ordered_value' => ['amount_minor' => (string) $entry['ordered'], 'currency' => $currency],
            ];
        })->sortBy(fn (array $r) => mb_strtolower((string) $r['party']['name']))->values()->all();
    }
}
