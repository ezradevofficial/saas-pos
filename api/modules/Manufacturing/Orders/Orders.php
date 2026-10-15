<?php

namespace Modules\Manufacturing\Orders;

use App\Core\CustomFields\CustomFieldWriter;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Boms\Explosion;
use Modules\Manufacturing\Boms\Versions;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Events\ProductionOrderClosed;
use Modules\Manufacturing\Events\ProductionOrderReleased;
use Modules\Manufacturing\Events\ValuedEvents;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Models\Issue;
use Modules\Manufacturing\Models\MaterialReturn;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Models\OrderMaterial;
use Modules\Manufacturing\Models\OrderOutput;
use Modules\Manufacturing\Models\Report;
use Modules\Manufacturing\Models\Setting;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Places;
use Modules\Manufacturing\Support\Qty;
use Modules\Manufacturing\Support\Units;

/**
 * MFG-10, MFG-11, MFG-14: production orders.
 *
 * - plan(): a location of a company, an item with a production BOM of the
 *   company, the version in force at the planned start (or one named), a
 *   quantity in a unit of the item; the explosion becomes its materials
 *   and by-products. Numbered, `planned`.
 * - update(): while planned (materials exploded again).
 * - release(): ledger and rates fixed; released at once, or through the
 *   `manufacturing.production_order` flow when the company asks.
 * - cancel(), close(): see the spec.
 */
class Orders
{
    public function __construct(
        private readonly Places $places,
        private readonly Units $units,
        private readonly Versions $versions,
        private readonly Explosion $explosion,
        private readonly DocumentNumbers $numbers,
        private readonly WorkflowEngine $engine,
        private readonly ModuleRegistry $modules,
        private readonly CustomFieldWriter $customFields,
        private readonly TenantContext $tenants,
        private readonly ValuedEvents $valued,
    ) {}

    /** @param array<string, mixed> $data */
    public function plan(array $data, User $by): Order
    {
        $place = $this->places->open((string) $data['location_id']);
        $item = Item::query()->whereKey((string) $data['item_id'])->whereNull('archived_at')->first();

        if ($item === null || ! $by->can('view', $item)) {
            throw Errors::make(422, 'item_unknown', field: 'item_id');
        }

        if ($item->company_id !== null && $item->company_id !== $place['company_id']) {
            throw Errors::make(422, 'item_other_company', ['item' => $item->name], 'item_id');
        }

        $bom = Bom::query()->where('company_id', $place['company_id'])->where('item_id', $item->id)->where('kind', Bom::PRODUCTION)->whereNull('archived_at')->first()
            ?? throw Errors::make(422, 'no_bom', ['item' => $item->name], 'item_id');
        $version = $this->version($bom, $data['bom_version_id'] ?? null, $data['planned_start'] ?? null, $place['company_id']);
        [$qty, $uomId, $qtyBase] = $this->quantity($item, $data['qty'] ?? null, $data['uom_id'] ?? null);

        return Errors::transaction(function () use ($data, $by, $place, $item, $bom, $version, $qty, $uomId, $qtyBase) {
            $order = new Order([
                'number' => $this->numbers->next(DocumentNumbers::ORDER, $place['company_id'], $place['branch_id'], (string) $data['location_id']),
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $data['location_id'],
                'item_id' => $item->id,
                'bom_id' => $bom->id,
                'bom_version_id' => $version->id,
                'qty' => (string) $qty,
                'uom_id' => $uomId,
                'qty_base' => (string) $qtyBase,
                'planned_start' => $data['planned_start'] ?? null,
                'planned_end' => $data['planned_end'] ?? null,
                'status' => Order::PLANNED,
                'note' => $data['note'] ?? null,
                'planned_by' => $by->id,
            ]);
            $this->customFields->fill(OrderEntity::KEY, $order, $data['custom'] ?? null, creating: true);
            $order->save();
            $this->customFields->saved(OrderEntity::KEY, $order);
            $this->explode($order, $version, $item);

            return $order->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Order $order, array $data, User $by): Order
    {
        // The quantity and unit are checked first: a unit the item does not have is refused whatever the status.
        $item = Item::query()->findOrFail($order->item_id);
        $quantity = array_key_exists('qty', $data) || array_key_exists('uom_id', $data)
            ? $this->quantity($item, $data['qty'] ?? $order->qty, $data['uom_id'] ?? $order->uom_id)
            : null;

        return Errors::transaction(function () use ($order, $data, $item, $quantity) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::PLANNED) {
                throw Errors::make(409, 'document_not_open');
            }

            if ($quantity !== null) {
                [$qty, $uomId, $qtyBase] = $quantity;
                $order->fill(['qty' => (string) $qty, 'uom_id' => $uomId, 'qty_base' => (string) $qtyBase]);
            }

            foreach (['planned_start', 'planned_end', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $order->{$field} = $data[$field];
                }
            }

            $this->customFields->fill(OrderEntity::KEY, $order, $data['custom'] ?? null, creating: false);
            $exploded = $order->isDirty('qty_base');
            $order->save();
            $this->customFields->saved(OrderEntity::KEY, $order);

            if ($exploded) {
                // A planned order has moved nothing: its derived rows are rebuilt for the new quantity.
                OrderMaterial::query()->where('order_id', $order->id)->delete();
                OrderOutput::query()->where('order_id', $order->id)->delete();
                $this->explode($order, BomVersion::query()->findOrFail($order->bom_version_id), $item);
            }

            return $order->refresh();
        });
    }

    /** MFG-11: ledger, rates and currency fixed; released, or waiting for the release flow. */
    public function release(Order $order, User $by): Order
    {
        $released = Errors::transaction(function () use ($order, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== Order::PLANNED) {
                throw Errors::make(409, 'document_not_open');
            }

            $settings = Setting::query()->where('company_id', $order->company_id)->first();
            $order->fill([
                'ledger' => $this->modules->isActive('inventory') ? Order::LEDGER_INVENTORY : Order::LEDGER_NONE,
                'labour_rate_minor' => (string) ($settings?->labour_rate_minor ?? 0),
                'overhead_rate_minor' => (string) ($settings?->overhead_rate_minor ?? 0),
                'currency' => Company::query()->whereKey($order->company_id)->value('base_currency'),
                'released_by' => $by->id,
            ]);

            if ($settings?->release_approval) {
                $order->fill(['status' => Order::PENDING_APPROVAL])->save();
                $workflow = $this->engine->start(OrderType::KEY, $order->id, $by);

                // A tenant's flow without an approval ends at once.
                if ($workflow->status === DocumentWorkflow::COMPLETED) {
                    $this->settle($order, (string) $workflow->outcome, $by->id);
                }

                return $order->refresh();
            }

            $this->settle($order, 'approved', null);

            return $order->refresh();
        });

        return $released;
    }

    /** WF-10: the release flow ended: approved (released) or rejected. */
    public function completed(string $orderId, string $outcome, ?string $userId): void
    {
        Errors::transaction(function () use ($orderId, $outcome, $userId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order !== null && $order->status === Order::PENDING_APPROVAL) {
                $this->settle($order, $outcome, $userId);
            }
        });
    }

    /** WF-11: the release flow was cancelled: the order is cancelled. */
    public function flowCancelled(string $orderId): void
    {
        Errors::transaction(function () use ($orderId) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order !== null && $order->status === Order::PENDING_APPROVAL) {
                $order->fill(['status' => Order::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    public function cancel(Order $order, User $by, string $reason): Order
    {
        return Errors::transaction(function () use ($order, $by, $reason) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $untouched = $order->status === Order::RELEASED
                && Issue::query()->where('order_id', $order->id)->doesntExist()
                && Report::query()->where('order_id', $order->id)->doesntExist();

            if (! in_array($order->status, [Order::PLANNED, Order::PENDING_APPROVAL], true) && ! $untouched) {
                throw Errors::make(409, 'document_not_open');
            }

            $workflow = $this->engine->current(OrderType::KEY, $order->id);

            if ($workflow !== null && $workflow->status === DocumentWorkflow::RUNNING) {
                $this->engine->cancel($workflow, null, $reason);
            }

            $order->fill(['status' => Order::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => $reason])->save();

            return $order->refresh();
        });
    }

    /** MFG-14, MFG-16: closed whatever it produced; what is left in WIP is the variance. */
    public function close(Order $order, User $by, ?string $note): Order
    {
        return Errors::transaction(function () use ($order, $by, $note) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, Order::RUNNING, true)) {
                throw Errors::make(409, 'document_not_open');
            }

            $variance = null;

            if ($order->isValued()) {
                $pending = Issue::query()->where('order_id', $order->id)->whereNull('valued_at')->exists()
                    || MaterialReturn::query()->where('order_id', $order->id)->whereNull('valued_at')->exists()
                    || Report::query()->where('order_id', $order->id)->whereIn('status', [Report::PENDING, Report::COSTED])->exists();

                if ($pending) {
                    throw Errors::make(409, 'valuation_pending');
                }

                $variance = BigInteger::of((string) $order->material_cost_minor)->plus((string) $order->labour_cost_minor)
                    ->plus((string) $order->overhead_cost_minor)->minus((string) $order->relieved_minor);
            }

            $order->fill([
                'status' => Order::CLOSED, 'closed_by' => $by->id, 'closed_at' => CarbonImmutable::now(), 'close_note' => $note,
                'variance_minor' => $variance === null ? null : (string) $variance,
            ])->save();

            if ($variance !== null && ! $variance->isZero()) {
                event($this->valued->variance($order));
            }

            event(new ProductionOrderClosed(...$this->eventArgs($order)));

            return $order->refresh();
        });
    }

    /** @return array<int, mixed> the arguments of an order's lifecycle event */
    public function eventArgs(Order $order, ?string $documentId = null): array
    {
        return [
            $this->tenants->require(), $order->id, $order->number, $order->company_id, $order->location_id, $order->item_id,
            Qty::show($order->qty_base), Qty::show($order->qty_good_base), Qty::show($order->qty_scrap_base), $documentId,
        ];
    }

    private function settle(Order $order, string $outcome, ?string $userId): void
    {
        if ($outcome !== 'approved') {
            $order->fill(['status' => Order::REJECTED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            return;
        }

        $order->fill([
            'status' => Order::RELEASED, 'released_at' => CarbonImmutable::now(),
            ...($userId === null ? [] : ['decided_by' => $userId, 'decided_at' => CarbonImmutable::now()]),
        ])->save();

        event(new ProductionOrderReleased(...$this->eventArgs($order)));
    }

    /** The version of $bom the order takes: one named (published, of the BOM), else the one in force at the planned start. */
    private function version(Bom $bom, ?string $versionId, ?string $plannedStart, string $companyId): BomVersion
    {
        if ($versionId !== null) {
            $version = BomVersion::query()->where('bom_id', $bom->id)->whereKey($versionId)->where('status', BomVersion::PUBLISHED)->first();

            return $version ?? throw Errors::make(422, 'version_other_bom', field: 'bom_version_id');
        }

        $zone = Company::query()->whereKey($companyId)->value('timezone') ?: 'UTC';
        $start = $plannedStart === null ? null : CarbonImmutable::parse($plannedStart, $zone)->startOfDay();
        $at = $start === null || $start->isPast() ? CarbonImmutable::now() : $start;

        return $this->versions->ofBom($bom->id, $at) ?? throw Errors::make(422, 'no_bom_in_force', ['item' => Item::query()->whereKey($bom->item_id)->value('name')], 'item_id');
    }

    /** @return array{0: BigDecimal, 1: string, 2: BigDecimal} the quantity, its unit and the base quantity */
    private function quantity(Item $item, mixed $qty, ?string $uomId): array
    {
        $uomId ??= $item->base_uom_id;
        $factor = $this->units->factor($item, $uomId) ?? throw Errors::make(422, 'uom_not_on_item', field: 'uom_id');

        try {
            $value = BigDecimal::of((string) $qty);
        } catch (MathException) {
            throw Errors::make(422, 'qty_invalid', field: 'qty');
        }

        if (! $value->isPositive()) {
            throw Errors::make(422, 'qty_invalid', field: 'qty');
        }

        if (! $this->units->fitsPrecision($uomId, $value)) {
            throw Errors::make(422, 'qty_precision', field: 'qty');
        }

        return [Qty::of($value), $uomId, Qty::of($value->multipliedBy($factor))];
    }

    /** MFG-10: the version exploded for the order's quantity: its materials and expected outputs. */
    private function explode(Order $order, BomVersion $version, Item $item): void
    {
        $at = $order->planned_start === null ? CarbonImmutable::now() : CarbonImmutable::parse($order->planned_start->format('Y-m-d'));
        $exploded = $this->explosion->explode($version, BigDecimal::of((string) $order->qty_base), max($at, CarbonImmutable::parse((string) $version->effective_from)));
        $units = Item::query()->whereKey(array_column($exploded['leaves'], 'item_id'))->pluck('base_uom_id', 'id');

        foreach ($exploded['leaves'] as $index => $leaf) {
            OrderMaterial::create([
                'order_id' => $order->id, 'line_no' => $index + 1, 'item_id' => $leaf['item_id'], 'uom_id' => $units[$leaf['item_id']],
                'qty_required_base' => (string) $leaf['qty_base'], 'expected_loss_base' => (string) $leaf['loss_base'],
            ]);
        }

        OrderOutput::create(['order_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id, 'kind' => OrderOutput::PRODUCT, 'qty_planned_base' => (string) $order->qty_base]);

        foreach ($exploded['by_products'] as $index => $byProduct) {
            OrderOutput::create([
                'order_id' => $order->id, 'line_no' => $index + 2, 'item_id' => $byProduct['item_id'], 'kind' => OrderOutput::BY_PRODUCT,
                'qty_planned_base' => (string) $byProduct['qty_base'], 'cost_share_percent' => $byProduct['cost_share_percent'],
            ]);
        }
    }
}
