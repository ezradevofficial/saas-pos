<?php

namespace Modules\Procurement\Documents;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Quote;
use Modules\Procurement\Models\QuoteLine;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\RequisitionLine;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqLine;
use Modules\Procurement\Models\RfqSupplier;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-03 to PRC-05: requests for quotation, the quotes the buyer types in,
 * their comparison in base currency and the award.
 *
 * - create() / update(): a draft at a branch, from an approved requisition
 *   (its lines when none are given) or blank; invited suppliers.
 * - issue(): numbered, needs lines, suppliers and a future deadline.
 * - quote(): one quote per invited supplier while issued (typed again, it
 *   replaces the previous one), each price also in base currency at the
 *   company's rate on the quote date (CUR-04).
 * - comparison(): per RFQ line every supplier's price in base currency, the
 *   lowest marked; per supplier its total.
 * - award(): one quote line per RFQ line (the lowest by default): a draft
 *   PO per supplier in its quote's currency and prices; the RFQ closes and
 *   its requisition is marked ordered.
 */
class Rfqs
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly DocumentNumbers $numbers,
        private readonly Pricing $pricing,
        private readonly Requisitions $requisitions,
        private readonly Orders $orders,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Rfq
    {
        return Tx::run(function () use ($data, $by) {
            $branch = Branch::query()->findOrFail($data['branch_id']);
            $requisition = empty($data['requisition_id']) ? null : $this->requisitions->approved($branch->company_id, $data['requisition_id']);
            $rfq = Rfq::query()->create([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'requisition_id' => $requisition?->id,
                'title' => $data['title'],
                'note' => $data['note'] ?? null,
                'deadline_at' => $data['deadline_at'] ?? null,
                'status' => Rfq::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeLines($rfq, $data['lines'] ?? $this->linesOf($requisition));
            $this->writeSuppliers($rfq, $data['supplier_ids'] ?? []);

            return $rfq->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Rfq $rfq, array $data): Rfq
    {
        return Tx::run(function () use ($rfq, $data) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

            if ($rfq->status !== Rfq::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            foreach (['title', 'note', 'deadline_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $rfq->{$field} = $data[$field];
                }
            }

            $rfq->save();

            if (isset($data['lines'])) {
                RfqLine::query()->where('rfq_id', $rfq->id)->delete();
                $this->writeLines($rfq, $data['lines']);
            }

            if (isset($data['supplier_ids'])) {
                RfqSupplier::query()->where('rfq_id', $rfq->id)->delete();
                $this->writeSuppliers($rfq, $data['supplier_ids']);
            }

            return $rfq->refresh();
        });
    }

    public function issue(Rfq $rfq): Rfq
    {
        return Tx::run(function () use ($rfq) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

            if ($rfq->status !== Rfq::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (! $rfq->lines()->exists()) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            if (! $rfq->suppliers()->exists()) {
                throw ProcurementError::make('suppliers_required', field: 'supplier_ids');
            }

            if ($rfq->deadline_at === null || $rfq->deadline_at->isPast()) {
                throw ProcurementError::make('deadline_in_past', field: 'deadline_at');
            }

            $rfq->fill([
                'number' => $rfq->number ?? $this->numbers->next(DocumentNumbers::RFQ, $rfq->company_id, $rfq->branch_id),
                'status' => Rfq::ISSUED,
                'issued_at' => CarbonImmutable::now(),
            ])->save();

            if ($rfq->requisition_id !== null) {
                $this->requisitions->markOrdered(Requisition::query()->lockForUpdate()->findOrFail($rfq->requisition_id));
            }

            return $rfq->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function quote(Rfq $rfq, array $data, User $by): Quote
    {
        return Tx::run(function () use ($rfq, $data, $by) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

            if ($rfq->status !== Rfq::ISSUED) {
                throw ProcurementError::make('rfq_not_issued', status: 409);
            }

            if (! $rfq->suppliers()->where('party_id', $data['party_id'])->exists()) {
                throw ProcurementError::make('supplier_not_invited', field: 'party_id');
            }

            $company = Company::query()->findOrFail($rfq->company_id);
            $currency = $this->resolver->currency($data['currency']);
            $date = CarbonImmutable::parse($data['quote_date']);
            $fx = $this->pricing->fx($company, $currency, Pricing::endOfDay($company, $date));
            $rfqLines = RfqLine::query()->where('rfq_id', $rfq->id)->get()->keyBy('id');
            $quote = Quote::query()->where('rfq_id', $rfq->id)->where('party_id', $data['party_id'])->first();

            if ($quote !== null) {
                QuoteLine::query()->where('quote_id', $quote->id)->delete();
            }

            $quote ??= new Quote(['rfq_id' => $rfq->id, 'party_id' => $data['party_id'], 'created_by' => $by->id]);
            $quote->fill([
                'reference' => $data['reference'] ?? null,
                'quote_date' => $date->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'currency' => $currency,
                'fx' => $fx,
                // CUR-04: base amounts are in the company's base currency (a USD/KES snapshot's base() is USD).
                'base_currency' => $company->base_currency,
                'status' => Quote::RECEIVED,
                'note' => $data['note'] ?? null,
            ])->save();
            $total = BigDecimal::zero();
            $baseTotal = BigDecimal::zero();

            foreach (array_values($data['lines']) as $index => $input) {
                $rfqLine = $rfqLines->get($input['rfq_line_id']) ?? throw ProcurementError::make('line_unknown', field: "lines.{$index}.rfq_line_id");
                $price = (int) $input['unit_price_minor'];
                $basePrice = Pricing::convert($fx, $price, $currency);
                QuoteLine::query()->create([
                    'quote_id' => $quote->id,
                    'rfq_line_id' => $rfqLine->id,
                    'qty' => (string) $rfqLine->qty,
                    'unit_price_minor' => $price,
                    'base_unit_price_minor' => $basePrice,
                    'lead_time_days' => $input['lead_time_days'] ?? null,
                    'note' => $input['note'] ?? null,
                ]);
                $total = $total->plus(BigDecimal::of((string) $rfqLine->qty)->multipliedBy($price)->toScale(0, RoundingMode::HalfUp));
                $baseTotal = $baseTotal->plus(BigDecimal::of((string) $rfqLine->qty)->multipliedBy($basePrice)->toScale(0, RoundingMode::HalfUp));
            }

            $quote->fill(['total_minor' => (string) $total, 'base_total_minor' => (string) $baseTotal])->save();

            return $quote->refresh();
        });
    }

    /**
     * PRC-05: the comparison grid.
     *
     * @return array{base_currency: ?string, lines: list<array<string, mixed>>, suppliers: list<array<string, mixed>>}
     */
    public function comparison(Rfq $rfq): array
    {
        $quotes = Quote::query()->with(['party', 'lines'])->where('rfq_id', $rfq->id)->orderBy('created_at')->get();
        $lines = [];

        foreach (RfqLine::query()->with(['item', 'uom'])->where('rfq_id', $rfq->id)->orderBy('line_no')->get() as $rfqLine) {
            $offers = [];

            foreach ($quotes as $quote) {
                $line = $quote->lines->firstWhere('rfq_line_id', $rfqLine->id);

                if ($line !== null) {
                    $offers[] = ['quote_id' => $quote->id, 'quote_line_id' => $line->id, 'party_id' => $quote->party_id, 'currency' => $quote->currency,
                        'unit_price_minor' => $line->unit_price_minor, 'base_unit_price_minor' => $line->base_unit_price_minor,
                        'lead_time_days' => $line->lead_time_days, 'selected' => $line->selected];
                }
            }

            $lowest = collect($offers)->sortBy([['base_unit_price_minor', 'asc'], ['lead_time_days', 'asc']])->first();

            $lines[] = [
                'rfq_line' => $rfqLine,
                'offers' => array_map(fn (array $offer) => [...$offer, 'lowest' => $lowest !== null && $offer['quote_line_id'] === $lowest['quote_line_id']], $offers),
            ];
        }

        return [
            'base_currency' => $quotes->first()?->base_currency,
            'lines' => $lines,
            'suppliers' => $quotes->map(fn (Quote $quote) => ['quote' => $quote, 'lines_quoted' => $quote->lines->count()])->all(),
        ];
    }

    /**
     * PRC-05: award one quote line per RFQ line ($selections: rfq line id =>
     * quote line id; lines left out take the lowest offer). Returns the draft POs.
     *
     * @param  array<string, string>  $selections
     * @return Collection<int, Order>
     */
    public function award(Rfq $rfq, array $selections, User $by): Collection
    {
        return Tx::run(function () use ($rfq, $selections, $by) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

            if ($rfq->status !== Rfq::ISSUED) {
                throw ProcurementError::make('rfq_not_issued', status: 409);
            }

            $grid = $this->comparison($rfq);
            $chosen = [];

            foreach ($grid['lines'] as $index => $line) {
                $rfqLine = $line['rfq_line'];
                $wanted = $selections[$rfqLine->id] ?? null;
                $offer = $wanted === null
                    ? collect($line['offers'])->firstWhere('lowest', true)
                    : (collect($line['offers'])->firstWhere('quote_line_id', $wanted) ?? throw ProcurementError::make('line_unknown', field: "selections.{$index}"));

                if ($offer !== null) {
                    $chosen[$offer['quote_id']][] = [$rfqLine, $offer];
                }
            }

            if ($chosen === []) {
                throw ProcurementError::make('no_quotes', status: 409);
            }

            $orders = collect();

            foreach ($chosen as $quoteId => $picks) {
                $quote = Quote::query()->findOrFail($quoteId);
                QuoteLine::query()->whereKey(array_map(fn ($pick) => $pick[1]['quote_line_id'], $picks))->update(['selected' => true]);
                $quote->fill(['status' => Quote::AWARDED])->save();
                $orders->push($this->orders->create([
                    'branch_id' => $rfq->branch_id,
                    'party_id' => $quote->party_id,
                    'currency' => $quote->currency,
                    'lines' => array_map(fn (array $pick) => [
                        'item_id' => $pick[0]->item_id,
                        'description' => $pick[0]->description,
                        'uom_id' => $pick[0]->uom_id,
                        'qty' => (string) $pick[0]->qty,
                        'unit_price_minor' => $pick[1]['unit_price_minor'],
                        'requisition_line_id' => $pick[0]->requisition_line_id,
                    ], $picks),
                ], $by, ['quote_id' => $quote->id, 'requisition_id' => $rfq->requisition_id]));
            }

            Quote::query()->where('rfq_id', $rfq->id)->whereNotIn('id', array_keys($chosen))->get()
                ->each(fn (Quote $quote) => $quote->fill(['status' => Quote::NOT_AWARDED])->save());
            $rfq->fill(['status' => Rfq::CLOSED, 'closed_at' => CarbonImmutable::now()])->save();

            return $orders;
        });
    }

    public function cancel(Rfq $rfq, User $by, string $reason): Rfq
    {
        return Tx::run(function () use ($rfq, $by, $reason) {
            $rfq = Rfq::query()->whereKey($rfq->id)->lockForUpdate()->firstOrFail();

            if (! in_array($rfq->status, [Rfq::DRAFT, Rfq::ISSUED], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $rfq->fill(['status' => Rfq::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $rfq->refresh();
        });
    }

    /** @return list<array<string, mixed>> */
    private function linesOf(?Requisition $requisition): array
    {
        if ($requisition === null) {
            return [];
        }

        return RequisitionLine::query()->where('requisition_id', $requisition->id)->orderBy('line_no')->get()
            ->map(fn (RequisitionLine $line) => ['item_id' => $line->item_id, 'description' => $line->description, 'uom_id' => $line->uom_id,
                'qty' => (string) $line->qty, 'requisition_line_id' => $line->id])->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Rfq $rfq, array $lines): void
    {
        foreach (array_values($lines) as $index => $input) {
            $line = $this->resolver->line($rfq->company_id, $input, "lines.{$index}");
            $requisitionLine = $input['requisition_line_id'] ?? null;

            if ($requisitionLine !== null && ($rfq->requisition_id === null
                || ! RequisitionLine::query()->where('requisition_id', $rfq->requisition_id)->whereKey($requisitionLine)->exists())) {
                throw ProcurementError::make('line_unknown', field: "lines.{$index}.requisition_line_id");
            }

            RfqLine::query()->create([
                'rfq_id' => $rfq->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'description' => $line['description'],
                'uom_id' => $line['uom_id'],
                'qty' => $line['qty'],
                'requisition_line_id' => $requisitionLine,
            ]);
        }
    }

    /** @param list<string> $supplierIds */
    private function writeSuppliers(Rfq $rfq, array $supplierIds): void
    {
        foreach (array_values(array_unique($supplierIds)) as $index => $partyId) {
            RfqSupplier::query()->create(['rfq_id' => $rfq->id, 'party_id' => $this->resolver->supplier($rfq->company_id, $partyId, "supplier_ids.{$index}")->id]);
        }
    }
}
