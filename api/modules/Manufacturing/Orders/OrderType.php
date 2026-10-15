<?php

namespace Modules\Manufacturing\Orders;

use App\Core\MasterData\Items\Item;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\Order;

/**
 * WF-01, MFG-11: `manufacturing.production_order`, the release of a
 * production order when its company asks for approval. Its scope is the
 * order's location; its planner never approves it (APR-07). The default
 * flow (WF-02) is one approval by a Branch Manager at the location;
 * conditions may route on the location, branch, company, item and
 * quantity. The engine reads orders only through this class
 * (architecture rule 1).
 */
class OrderType extends DocumentType
{
    public const KEY = 'manufacturing.production_order';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'manufacturing.workflow.order.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('item', 'manufacturing.workflow.fields.item', 'core.item'),
            FieldDefinition::number('qty', 'manufacturing.workflow.fields.qty'),
            FieldDefinition::reference('location', 'manufacturing.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'manufacturing.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'manufacturing.workflow.fields.company', 'core.company'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $order = $this->find($documentId);

        return $order === null ? [] : [
            'item' => $order->item_id,
            'qty' => (float) $order->qty_base,
            'location' => $order->location_id,
            'branch' => $order->branch_id,
            'company' => $order->company_id,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $order = $this->find($documentId);

        return $order === null ? null : new DocumentScope($order->company_id, $order->branch_id, $order->location_id);
    }

    public function viewPermission(): string
    {
        return 'manufacturing.order.view';
    }

    public function actPermission(): string
    {
        return 'manufacturing.order.approve';
    }

    public function requesterId(string $documentId): ?string
    {
        $order = $this->find($documentId);

        return $order?->released_by ?? $order?->planned_by;
    }

    public function documentLink(string $documentId): string
    {
        return '/manufacturing/orders/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    /** APR-04: the number and the product; an order carries no amount. */
    public function summary(string $documentId): array
    {
        $order = $this->find($documentId);

        if ($order === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return ['number' => $order->number, 'title' => Item::query()->whereKey($order->item_id)->value('name'), 'amount' => null];
    }

    public function amountLabel(): ?string
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'manufacturing.workflow.order';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any']],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __("{$prefix}.approved")],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __("{$prefix}.rejected")],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    private function find(string $documentId): ?Order
    {
        return Str::isUuid($documentId) ? Order::query()->find($documentId) : null;
    }
}
