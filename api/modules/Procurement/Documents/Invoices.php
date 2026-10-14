<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Procurement\Events\SupplierInvoiceApproved;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\InvoiceLine;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-15: supplier invoices with three-way match.
 *
 * - create() / update(): an invoice of the PO's supplier in the PO's
 *   currency (the supplier's own number unique per supplier), lines naming
 *   PO lines (tax code defaults to the PO line's), amounts computed, then
 *   matched (ThreeWayMatch) against the PO and its posted GRNs: `matched`
 *   or `exception`.
 * - approve(): matched, or an exception with `procurement.invoice.override`
 *   and a reason: matched again, amounts strict (`tax_rate_missing`), the
 *   rate on the invoice date and base amounts frozen (CUR-04), the received
 *   value it clears (GRNI: the lines' GRN base value per accepted unit ×
 *   quantity), numbered, the PO's invoiced quantities and status updated;
 *   then SupplierInvoiceApproved (decision 3).
 * - cancel(): any invoice not approved.
 */
class Invoices
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly Pricing $pricing,
        private readonly DocumentNumbers $numbers,
        private readonly Orders $orders,
        private readonly BaseCurrencyLock $baseLock,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by): Invoice
    {
        return Tx::run(function () use ($data, $by) {
            $order = Order::query()->whereKey($data['order_id'])->firstOrFail();

            if (! in_array($order->status, Order::INVOICEABLE, true)) {
                throw ProcurementError::make('po_not_approved', field: 'order_id');
            }

            $this->assertReference($order->party_id, (string) $data['supplier_reference'], null);
            $invoice = Invoice::query()->create([
                'supplier_reference' => $data['supplier_reference'],
                'company_id' => $order->company_id,
                'branch_id' => $order->branch_id,
                'party_id' => $order->party_id,
                'order_id' => $order->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'currency' => $order->currency,
                'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? $order->tax_inclusive),
                'status' => Invoice::DRAFT,
                'created_by' => $by->id,
            ]);
            $this->writeLines($order, $invoice, $data['lines']);
            $this->match($invoice);

            return $invoice->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return Tx::run(function () use ($invoice, $data) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [Invoice::DRAFT, Invoice::MATCHED, Invoice::EXCEPTION], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            if (array_key_exists('supplier_reference', $data)) {
                $this->assertReference($invoice->party_id, (string) $data['supplier_reference'], $invoice->id);
            }

            foreach (['supplier_reference', 'invoice_date', 'due_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $invoice->{$field} = $data[$field];
                }
            }

            if (array_key_exists('tax_inclusive', $data)) {
                $invoice->tax_inclusive = (bool) $data['tax_inclusive'];
            }

            $invoice->save();
            $order = Order::query()->findOrFail($invoice->order_id);

            if (isset($data['lines'])) {
                InvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
                $this->writeLines($order, $invoice, $data['lines']);
            } else {
                $this->computeLines($invoice, strict: false);
            }

            $this->match($invoice);

            return $invoice->refresh();
        });
    }

    /** PRC-15: (re)match the invoice; sets each line's result and the invoice's status. */
    public function match(Invoice $invoice): Invoice
    {
        $settings = Settings::query()->where('company_id', $invoice->company_id)->first();
        $orderLines = OrderLine::query()->where('order_id', $invoice->order_id)->get()->keyBy('id');
        $exception = false;

        foreach (InvoiceLine::query()->where('invoice_id', $invoice->id)->get() as $line) {
            $orderLine = $orderLines->get($line->order_line_id);
            $accepted = (string) BigDecimal::of((string) $orderLine->received_qty)->minus((string) $orderLine->rejected_qty);
            $result = ThreeWayMatch::line((string) $line->qty, $line->unit_price_minor, $orderLine->unit_price_minor, $accepted, (string) $orderLine->invoiced_qty,
                (string) ($settings?->price_tolerance_percent ?? '0'), (string) ($settings?->qty_tolerance_percent ?? '0'));
            $line->fill(['match_status' => $result['status'], 'price_variance_minor' => $result['price_variance_minor'], 'qty_variance' => $result['qty_variance']])->save();
            $exception = $exception || $result['status'] !== InvoiceLine::MATCHED;
        }

        $invoice->fill(['status' => $exception ? Invoice::EXCEPTION : Invoice::MATCHED])->save();

        return $invoice;
    }

    public function approve(Invoice $invoice, User $by, bool $mayOverride, ?string $reason): Invoice
    {
        $event = null;

        $approved = Tx::run(function () use ($invoice, $by, $mayOverride, $reason, &$event) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [Invoice::MATCHED, Invoice::EXCEPTION], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $order = Order::query()->whereKey($invoice->order_id)->lockForUpdate()->firstOrFail();
            $orderLines = OrderLine::query()->where('order_id', $order->id)->lockForUpdate()->get()->keyBy('id');
            $company = Company::query()->findOrFail($invoice->company_id);
            $base = $this->baseLock->lock($company);
            $this->computeLines($invoice, strict: true);
            $this->match($invoice);

            if ($invoice->status === Invoice::EXCEPTION) {
                if (! $mayOverride) {
                    throw ProcurementError::make('match_exception', status: 403);
                }

                if (trim((string) $reason) === '') {
                    throw ProcurementError::make('override_reason_required', field: 'override_reason');
                }
            }

            $fx = $this->pricing->fx($company, $invoice->currency, Pricing::endOfDay($company, $invoice->invoice_date));
            $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('line_no')->get();
            $items = Item::query()->whereKey($orderLines->pluck('item_id')->filter()->unique()->all())->get()->keyBy('id');
            $baseNet = 0;
            $baseTax = 0;
            $baseGrni = 0;
            $documentLines = [];

            foreach ($lines as $line) {
                $orderLine = $orderLines->get($line->order_line_id);
                $lineBaseNet = Pricing::convert($fx, $line->net_minor, $invoice->currency);
                $lineBaseTax = Pricing::convert($fx, $line->tax_minor, $invoice->currency);
                $lineGrni = $this->grni($orderLine, (string) $line->qty);
                $line->fill(['base_net_minor' => $lineBaseNet, 'base_grni_minor' => $lineGrni])->save();
                $orderLine->fill(['invoiced_qty' => (string) BigDecimal::of((string) $orderLine->invoiced_qty)->plus((string) $line->qty)])->save();
                $baseNet += $lineBaseNet;
                $baseTax += $lineBaseTax;
                $baseGrni += $lineGrni;
                $documentLines[] = [
                    'description' => $orderLine->description,
                    'item_id' => $orderLine->item_id,
                    'qty' => Receipts::decimal((string) $line->qty),
                    'unit_price_minor' => (string) $line->unit_price_minor,
                    'net_minor' => (string) $line->net_minor,
                    'tax_code_id' => $line->tax_code_id,
                    'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                    'tax_minor' => (string) $line->tax_minor,
                    'total_minor' => (string) $line->total_minor,
                    'base_net_minor' => (string) $lineBaseNet,
                    'base_grni_minor' => (string) $lineGrni,
                    'cost_centre_id' => $orderLine->cost_centre_id,
                    'project_id' => $orderLine->project_id,
                    'kind' => $orderLine->item_id !== null && $items->get($orderLine->item_id)?->type === 'stock' ? 'stock' : 'expense',
                ];
            }

            $invoice->fill([
                'number' => $invoice->number ?? $this->numbers->next(DocumentNumbers::INVOICE, $invoice->company_id, $invoice->branch_id, at: $invoice->invoice_date->setTime(12, 0)),
                'status' => Invoice::APPROVED,
                'fx' => $fx,
                'base_currency' => $base,
                'base_subtotal_minor' => $baseNet,
                'base_tax_minor' => $baseTax,
                'base_total_minor' => $baseNet + $baseTax,
                'base_grni_minor' => $baseGrni,
                'override_reason' => $invoice->status === Invoice::EXCEPTION ? trim((string) $reason) : null,
                'approved_by' => $by->id,
                'approved_at' => CarbonImmutable::now(),
            ])->save();
            $this->orders->invoiced($order->refresh());

            $dimensions = ['party_id' => $invoice->party_id, 'branch_id' => $invoice->branch_id];

            foreach (['cost_centre_id', 'project_id'] as $dimension) {
                $values = collect($documentLines)->pluck($dimension)->unique();
                $dimensions[$dimension] = $values->count() === 1 ? $values->first() : null;
            }

            $event = new SupplierInvoiceApproved((string) $invoice->tenant_id, $invoice->id, $invoice->company_id, $invoice->invoice_date->toDateString(), $base,
                [
                    'net' => (string) $baseNet, 'tax' => (string) $baseTax, 'total' => (string) ($baseNet + $baseTax),
                    'grni' => (string) $baseGrni, 'price_variance' => (string) ($baseNet - $baseGrni),
                ],
                $fx->jsonSerialize(), $dimensions, (string) $invoice->number,
                [
                    'invoice_id' => $invoice->id, 'number' => $invoice->number, 'supplier_reference' => $invoice->supplier_reference,
                    'party_id' => $invoice->party_id, 'company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id,
                    'invoice_date' => $invoice->invoice_date->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => $invoice->currency, 'tax_inclusive' => (bool) $invoice->tax_inclusive, 'lines' => $documentLines,
                    'totals' => ['net' => (string) $invoice->subtotal_minor, 'tax' => (string) $invoice->tax_minor, 'total' => (string) $invoice->total_minor],
                    'base_totals' => ['net' => (string) $baseNet, 'tax' => (string) $baseTax, 'total' => (string) ($baseNet + $baseTax)],
                ],
            );

            return $invoice->refresh();
        });

        event($event);

        return $approved;
    }

    public function cancel(Invoice $invoice, User $by, string $reason): Invoice
    {
        return Tx::run(function () use ($invoice, $by, $reason) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [Invoice::DRAFT, Invoice::MATCHED, Invoice::EXCEPTION], true)) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $invoice->fill(['status' => Invoice::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $invoice->refresh();
        });
    }

    /** The received base value the quantity clears: the line's posted GRN base value per accepted unit × qty (half up). */
    private function grni(OrderLine $orderLine, string $qty): int
    {
        $received = ReceiptLine::query()->where('order_line_id', $orderLine->id)
            ->whereIn('receipt_id', fn ($q) => $q->select('id')->from('procurement_receipts')->where('status', 'posted'))
            ->selectRaw('coalesce(sum(base_value_minor), 0) as value, coalesce(sum(accepted_qty), 0) as qty')->first();

        if ($received === null || ! BigDecimal::of((string) $received->qty)->isPositive()) {
            return 0;
        }

        return (int) (string) BigDecimal::of((string) $received->value)->multipliedBy($qty)->dividedBy((string) $received->qty, 0, RoundingMode::HalfUp);
    }

    private function assertReference(string $partyId, string $reference, ?string $exceptId): void
    {
        $taken = Invoice::query()->where('party_id', $partyId)->where('status', '<>', Invoice::CANCELLED)
            ->whereRaw('lower(supplier_reference) = lower(?)', [$reference])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw ProcurementError::make('duplicate_reference', field: 'supplier_reference');
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Order $order, Invoice $invoice, array $lines): void
    {
        $orderLines = OrderLine::query()->where('order_id', $order->id)->get()->keyBy('id');
        $seen = [];

        foreach (array_values($lines) as $index => $input) {
            $orderLine = $orderLines->get($input['order_line_id'] ?? '') ?? throw ProcurementError::make('line_not_on_po', field: "lines.{$index}.order_line_id");

            if (isset($seen[$orderLine->id])) {
                throw ProcurementError::make('line_repeated', field: "lines.{$index}.order_line_id");
            }

            $seen[$orderLine->id] = true;
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'order_line_id' => $orderLine->id,
                'line_no' => $index + 1,
                'qty' => LineResolver::qty((string) $input['qty'], "lines.{$index}.qty"),
                'unit_price_minor' => (int) ($input['unit_price_minor'] ?? $orderLine->unit_price_minor),
                'tax_code_id' => array_key_exists('tax_code_id', $input)
                    ? $this->resolver->taxCode($order->company_id, $input['tax_code_id'], "lines.{$index}.tax_code_id")
                    : $orderLine->tax_code_id,
            ]);
        }

        $this->computeLines($invoice, strict: false);
    }

    private function computeLines(Invoice $invoice, bool $strict): void
    {
        $company = Company::query()->findOrFail($invoice->company_id);
        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->get();

        foreach ($lines as $line) {
            $line->fill($this->pricing->line($company, $invoice->currency, (string) $line->qty, $line->unit_price_minor, $line->tax_code_id, (bool) $invoice->tax_inclusive, $invoice->invoice_date, $strict))->save();
        }

        $invoice->fill([
            'subtotal_minor' => (int) $lines->sum('net_minor'),
            'tax_minor' => (int) $lines->sum('tax_minor'),
            'total_minor' => (int) $lines->sum('total_minor'),
        ])->save();
    }
}
