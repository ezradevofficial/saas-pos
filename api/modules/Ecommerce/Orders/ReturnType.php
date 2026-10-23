<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Policies\ReturnPolicy;
use Modules\Ecommerce\Support\Access;

/**
 * WF-01, APR, RBAC-06 (review H1): `ecommerce.return`, a return whose
 * refund, added to the order's other refunds (refunded or due), is above
 * the user's `max_refund_amount`: it goes for approval instead of being
 * refused, as CRM sends a quote whose discount is above the saver's limit.
 * Until approved, nothing of it moves (stock, posting, fiscal note, money);
 * a rejection gives its quantities back to the order.
 *
 * The default flow: one Branch Manager at the order's branch approves (any
 * one of them); the requester never approves their own (APR-07).
 */
class ReturnType extends DocumentType
{
    public const KEY = 'ecommerce.return';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ecommerce.workflow.return.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('refund', 'ecommerce.workflow.fields.refund'),
            FieldDefinition::money('order_refunds', 'ecommerce.workflow.fields.order_refunds'),
            FieldDefinition::reference('company', 'ecommerce.workflow.fields.company', 'core.company'),
            FieldDefinition::reference('branch', 'ecommerce.workflow.fields.branch', 'core.branch'),
            FieldDefinition::string('currency', 'ecommerce.workflow.fields.currency'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        [$return, $order] = $this->find($documentId);

        if ($return === null) {
            return [];
        }

        return [
            'refund' => Money::ofMinor((string) $return->refund_minor, $return->currency),
            'order_refunds' => Money::ofMinor((string) Returns::refundsOf($order), $return->currency),
            'company' => $order->company_id,
            'branch' => $order->branch_id,
            'currency' => $return->currency,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        [$return, $order] = $this->find($documentId);

        return $return === null ? null : new DocumentScope($order->company_id, $order->branch_id, $order->location_id);
    }

    public function viewPermission(): string
    {
        return 'ecommerce.order.view';
    }

    public function actPermission(): string
    {
        return 'ecommerce.order.refund';
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)[0]?->approval_requested_by;
    }

    public function documentLink(string $documentId): string
    {
        $return = $this->find($documentId)[0];

        return $return === null ? '/ecommerce/orders' : '/ecommerce/orders/'.rawurlencode($return->order_id);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function amountLabel(): ?string
    {
        return 'ecommerce.workflow.fields.refund';
    }

    public function summary(string $documentId): array
    {
        $return = $this->find($documentId)[0];

        return $return === null ? ['number' => null, 'title' => null, 'amount' => null] : [
            'number' => $return->number,
            'title' => $return->reason,
            'amount' => Money::ofMinor((string) $return->refund_minor, $return->currency)->jsonSerialize(),
        ];
    }

    public function sees(User $user, string $documentId): bool
    {
        $return = $this->find($documentId)[0];

        return $return !== null && app(ReturnPolicy::class)->viewReturn($user, $return);
    }

    public function visibleDocumentIds(User $user): ?Builder
    {
        return app(Access::class)->byLocation(OrderReturn::query(), $user, 'ecommerce.order.view')->select('ecom_returns.id')->toBase();
    }

    public function defaultFlow(?string $country): ?array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __('ecommerce.workflow.return.start')],
                ['id' => 'approve', 'type' => 'approval', 'name' => __('ecommerce.workflow.return.approve'),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any']],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __('ecommerce.workflow.return.approved')],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __('ecommerce.workflow.return.rejected')],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    /** @return array{0: ?OrderReturn, 1: ?Order} */
    private function find(string $documentId): array
    {
        $return = Str::isUuid($documentId) ? OrderReturn::query()->find($documentId) : null;

        return [$return, $return === null ? null : Order::query()->find($return->order_id)];
    }
}
