<?php

namespace Modules\Ecommerce\Contracts;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Models\Order;

/**
 * Read contract (docs/modules/ecommerce.md ECOM-34): a customer's online
 * orders for another module's view (the CRM customer 360), so it never
 * reads `ecom_*` tables itself. Answers only while E-commerce is active
 * and only what the reader sees with `ecommerce.order.view` (at the
 * order's location or above, RBAC-04); null otherwise. Cancelled orders
 * count in `recent` but not in the totals.
 */
final class CustomerOrders
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ScopeResolver $resolver,
    ) {}

    /**
     * @return array{count: int, totals: list<array{currency: string, total_minor: string}>, last_ordered_at: ?string, recent: list<array{id: string, number: string, placed_at: string, currency: string, total_minor: string, status: string, channel: string}>}|null
     */
    public function forCustomer(User $reader, string $partyId, int $limit = 10): ?array
    {
        if (! $this->modules->isActive(EcommerceServiceProvider::MODULE) || ! $this->resolver->can($reader, 'ecommerce.order.view')) {
            return null;
        }

        $visible = fn (): Builder => $this->resolver->visibleIds($reader, 'ecommerce.order.view')
            ->applyTo(Order::query()->where('party_id', $partyId), Scope::LOCATION);
        $counted = fn (): Builder => $visible()->whereNotIn('status', [Order::CANCELLED, Order::RETURNED]);
        $recent = $visible()->orderByDesc('placed_at')->limit($limit)->get();

        return [
            'count' => $counted()->count(),
            'totals' => $counted()->groupBy('currency')->orderBy('currency')->selectRaw('currency, sum(total_minor) as total')->get()
                ->map(fn ($row) => ['currency' => $row->currency, 'total_minor' => (string) $row->total])->values()->all(),
            'last_ordered_at' => $recent->first()?->placed_at?->toIso8601String(),
            'recent' => $recent->map(fn (Order $order) => [
                'id' => $order->id, 'number' => $order->number, 'placed_at' => $order->placed_at->toIso8601String(), 'currency' => $order->currency,
                'total_minor' => (string) $order->total_minor, 'status' => $order->status, 'channel' => $order->channel,
            ])->values()->all(),
        ];
    }
}
