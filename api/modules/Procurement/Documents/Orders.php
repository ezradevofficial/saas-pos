<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Procurement\CustomFields\OrderEntity;
use Modules\Procurement\Events\PurchaseOrderApproved;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Models\RequisitionLine;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\Pricing;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-13: purchase orders.
 *
 * - create() / update(): a draft for one supplier (MD-01) at a branch,
 *   delivered to a location of the branch's company, in an active
 *   currency, lines checked against the company, amounts computed
 *   (Pricing: a line whose tax code has no confirmed rate is kept without
 *   tax, "Rate needed").
 * - submit(): the amounts again, strict (a "Rate needed" code refuses,
 *   `tax_rate_missing`), the company's base currency locked (CUR-02), the
 *   rate on the order date frozen with the base totals (CUR-04), numbered
 *   (NUM-01) and the `procurement.po` flow started (pending).
 * - completed() / cancelled(): approved (PurchaseOrderApproved) or
 *   rejected; WF-11.
 * - cancel(): a draft, pending, or approved PO with nothing received.
 * - received(): the status from the lines' received quantities (PRC-14).
 */
class Orders
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly Pricing $pricing,
        private readonly DocumentNumbers $numbers,
        private readonly WorkflowEngine $engine,
        private readonly CustomFieldWriter $customFields,
        private readonly BaseCurrencyLock $baseLock,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $by, array $sources = []): Order
    {
        return Tx::run(function () use ($data, $by, $sources) {
            $branch = Branch::query()->with('company')->findOrFail($data['branch_id']);
            $company = $branch->company;
            $party = $this->resolver->supplier($company->id, $data['party_id']);

            // PRC-02: a PO typed from an approved requisition takes it on.
            if (! empty($data['requisition_id'])) {
                $requisition = app(Requisitions::class)->approved($branch, $data['requisition_id'], $by);
                app(Requisitions::class)->markOrdered($requisition);
                $sources['requisition_id'] = $requisition->id;
            }

            $order = new Order([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'party_id' => $party->id,
                'status' => Order::DRAFT,
                'requested_by' => $by->id,
                'currency' => $this->resolver->currency($data['currency'] ?? ($party->currency ?: $company->base_currency)),
                'order_date' => $data['order_date'] ?? CarbonImmutable::now($company->timezone ?: 'UTC')->toDateString(),
                'location_id' => $this->location($company, $branch, $data['location_id'] ?? null),
                ...$sources,
            ]);
            $this->fill($order, $data);
            $this->customFields->fill(OrderEntity::KEY, $order, $data['custom'] ?? null, creating: true);
            $order->save();
            $this->customFields->saved(OrderEntity::KEY, $order);
            $this->writeLines($company, $order, $data['lines']);

            return $order->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Order $order, array $data): Order
    {
        return Tx::run(function () use ($order, $data) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            $branch = Branch::query()->with('company')->findOrFail($order->branch_id);
            $company = $branch->company;

            if (array_key_exists('party_id', $data)) {
                $order->party_id = $this->resolver->supplier($company->id, $data['party_id'])->id;
            }

            if (array_key_exists('currency', $data)) {
                $order->currency = $this->resolver->currency((string) $data['currency']);
            }

            if (array_key_exists('location_id', $data)) {
                $order->location_id = $this->location($company, $branch, $data['location_id']);
            }

            if (array_key_exists('order_date', $data)) {
                $order->order_date = $data['order_date'];
            }

            $this->fill($order, $data);
            $this->customFields->fill(OrderEntity::KEY, $order, $data['custom'] ?? null, creating: false);
            $order->save();
            $this->customFields->saved(OrderEntity::KEY, $order);

            if (isset($data['lines'])) {
                OrderLine::query()->where('order_id', $order->id)->delete();
                $this->writeLines($company, $order, $data['lines']);
            } else {
                // A currency, date or inclusive change recomputes the lines.
                $this->recompute($company, $order, strict: false);
            }

            return $order->refresh();
        });
    }

    public function submit(Order $order, User $by): Order
    {
        return Tx::run(function () use ($order, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::DRAFT) {
                throw ProcurementError::make('document_not_draft', status: 409);
            }

            if (! $order->lines()->exists()) {
                throw ProcurementError::make('document_empty', field: 'lines');
            }

            $company = Company::query()->findOrFail($order->company_id);
            $this->resolver->supplier($company->id, $order->party_id);
            $this->resolver->currency($order->currency);
            $base = $this->baseLock->lock($company);
            $this->recompute($company, $order, strict: true);
            $fx = $this->pricing->fx($company, $order->currency, Pricing::endOfDay($company, $order->order_date));
            $baseNet = 0;
            $baseTax = 0;

            foreach ($order->lines()->get() as $line) {
                $baseNet += Pricing::convert($fx, $line->net_minor, $order->currency);
                $baseTax += Pricing::convert($fx, $line->tax_minor, $order->currency);
            }

            $order->fill([
                'number' => $order->number ?? $this->numbers->next(DocumentNumbers::PO, $order->company_id, $order->branch_id, $order->location_id, $order->order_date->setTime(12, 0)),
                'status' => Order::PENDING,
                'submitted_at' => CarbonImmutable::now(),
                'fx' => $fx,
                'base_currency' => $base,
                'base_subtotal_minor' => $baseNet,
                'base_tax_minor' => $baseTax,
                'base_total_minor' => $baseNet + $baseTax,
            ])->save();
            $this->engine->start(OrderType::KEY, $order->id, $by);

            return $order->refresh();
        });
    }

    /** WF-10: the flow approved or rejected the PO. */
    public function completed(string $orderId, string $outcome, ?string $userId): void
    {
        Tx::run(function () use ($orderId, $outcome, $userId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== Order::PENDING) {
                return;
            }

            $approved = $outcome === 'approved';
            $order->fill(['status' => $approved ? Order::APPROVED : Order::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            if ($approved) {
                PurchaseOrderApproved::dispatch((string) $order->tenant_id, $order->id, $order->company_id, $order->branch_id, $order->party_id, (string) $order->number,
                    $order->currency, (string) $order->total_minor, (string) $order->base_currency, (string) $order->base_total_minor);
            }
        });
    }

    /** WF-11: the PO's flow was cancelled. */
    public function cancelled(string $orderId): void
    {
        Tx::run(function () use ($orderId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order !== null && $order->status === Order::PENDING) {
                $order->fill(['status' => Order::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Order $order, User $by, string $reason): Order
    {
        return Tx::run(function () use ($order, $by, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $nothingReceived = ! $order->receipts()->where('status', '<>', 'cancelled')->exists() && ! $order->invoices()->where('status', '<>', 'cancelled')->exists();

            if (! in_array($order->status, [Order::DRAFT, Order::PENDING, Order::APPROVED], true) || ! $nothingReceived) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $workflow = $this->engine->current(OrderType::KEY, $order->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $order->fill(['status' => Order::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $order->refresh();
        });
    }

    /** PRC-13: the PO was sent to the supplier (printed or emailed). */
    public function markSent(Order $order): Order
    {
        if (! in_array($order->status, [Order::APPROVED, Order::PARTIALLY_RECEIVED, Order::RECEIVED], true)) {
            throw ProcurementError::make('po_not_approved', status: 409);
        }

        $order->fill(['sent_at' => CarbonImmutable::now()])->save();

        return $order;
    }

    /** PRC-14: the status follows the received quantities (inside the GRN's transaction). */
    public function received(Order $order): void
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->get();
        $all = $lines->every(fn (OrderLine $line) => BigDecimal::of((string) $line->received_qty)->minus((string) $line->rejected_qty)->isGreaterThanOrEqualTo((string) $line->qty));
        $some = $lines->contains(fn (OrderLine $line) => BigDecimal::of((string) $line->received_qty)->isPositive());
        $status = $all ? Order::RECEIVED : ($some ? Order::PARTIALLY_RECEIVED : Order::APPROVED);

        if ($status !== $order->status) {
            $order->fill(['status' => $status])->save();
        }
    }

    /** PRC-15: a received PO whose every accepted quantity is invoiced is closed. */
    public function invoiced(Order $order): void
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->get();
        $done = $order->status === Order::RECEIVED && $lines->every(fn (OrderLine $line) => BigDecimal::of((string) $line->invoiced_qty)
            ->isGreaterThanOrEqualTo(BigDecimal::of((string) $line->received_qty)->minus((string) $line->rejected_qty)));

        if ($done) {
            $order->fill(['status' => Order::CLOSED, 'closed_at' => CarbonImmutable::now()])->save();
        }
    }

    /** @param array<string, mixed> $data */
    private function fill(Order $order, array $data): void
    {
        foreach (['expected_on', 'terms'] as $field) {
            if (array_key_exists($field, $data)) {
                $order->{$field} = $data[$field];
            }
        }

        if (array_key_exists('tax_inclusive', $data)) {
            $order->tax_inclusive = (bool) $data['tax_inclusive'];
        }
    }

    /** The delivery location: one of the company's (any branch), active; the branch's first by default. */
    private function location(Company $company, Branch $branch, ?string $locationId): string
    {
        if ($locationId === null) {
            return Location::query()->where('branch_id', $branch->id)->whereNull('archived_at')->orderBy('created_at')->value('id')
                ?? throw ProcurementError::make('location_required', field: 'location_id');
        }

        return Location::query()->whereKey($locationId)->whereNull('archived_at')
            ->whereIn('branch_id', fn ($q) => $q->select('id')->from('branches')->where('company_id', $company->id))->value('id')
            ?? throw ProcurementError::make('location_not_of_company', field: 'location_id');
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Company $company, Order $order, array $lines): void
    {
        foreach (array_values($lines) as $index => $input) {
            $line = $this->resolver->line($company->id, $input, "lines.{$index}");
            $requisitionLine = $input['requisition_line_id'] ?? null;

            if ($requisitionLine !== null && ($order->requisition_id === null
                || ! RequisitionLine::query()->where('requisition_id', $order->requisition_id)->whereKey($requisitionLine)->exists())) {
                throw ProcurementError::make('line_unknown', field: "lines.{$index}.requisition_line_id");
            }

            $model = new OrderLine([
                'order_id' => $order->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'description' => $line['description'],
                'uom_id' => $line['uom_id'],
                'qty' => $line['qty'],
                'unit_price_minor' => (int) $input['unit_price_minor'],
                'tax_code_id' => $line['tax_code_id'],
                'cost_centre_id' => $line['cost_centre_id'],
                'project_id' => $line['project_id'],
                'requisition_line_id' => $requisitionLine,
            ]);
            $model->fill($this->pricing->line($company, $order->currency, $line['qty'], (int) $input['unit_price_minor'], $line['tax_code_id'], (bool) $order->tax_inclusive, $order->order_date, strict: false));
            $model->save();
        }

        $this->totals($order);
    }

    private function recompute(Company $company, Order $order, bool $strict): void
    {
        foreach (OrderLine::query()->where('order_id', $order->id)->get() as $line) {
            $line->fill($this->pricing->line($company, $order->currency, (string) $line->qty, $line->unit_price_minor, $line->tax_code_id, (bool) $order->tax_inclusive, $order->order_date, $strict))->save();
        }

        $this->totals($order);
    }

    private function totals(Order $order): void
    {
        $lines = OrderLine::query()->where('order_id', $order->id)->get();
        $order->fill([
            'subtotal_minor' => (int) $lines->sum('net_minor'),
            'tax_minor' => (int) $lines->sum('tax_minor'),
            'total_minor' => (int) $lines->sum('total_minor'),
        ])->save();
    }

    /** Whether a line's tax code still needs a rate ("Rate needed"). */
    public static function rateNeeded(OrderLine $line): bool
    {
        return $line->tax_code_id !== null && $line->tax_rate === null
            && ! TaxCode::query()->whereKey($line->tax_code_id)->where('kind', 'exempt')->exists();
    }
}
