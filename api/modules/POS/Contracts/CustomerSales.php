<?php

namespace Modules\POS\Contracts;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\POS\Models\Sale;
use Modules\POS\PosServiceProvider;

/**
 * Read contract (docs/modules/crm.md, CRM-25): a customer's POS sales for
 * another module's view (the CRM customer 360), so it never reads
 * `pos_*` tables itself. Answers only while POS is active and only what
 * the reader sees with `pos.sale.view` (at the sale's location or above,
 * RBAC-04); null otherwise.
 */
final class CustomerSales
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ScopeResolver $resolver,
    ) {}

    /**
     * @return array{count: int, totals: list<array{currency: string, total_minor: string}>, last_sold_at: ?string, recent: list<array{id: string, receipt_number: string, sold_at: string, currency: string, total_minor: string, status: string}>}|null
     */
    public function forCustomer(User $reader, string $partyId, int $limit = 10): ?array
    {
        if (! $this->modules->isActive(PosServiceProvider::MODULE) || ! $this->resolver->can($reader, 'pos.sale.view')) {
            return null;
        }

        $visible = fn (): Builder => $this->resolver->visibleIds($reader, 'pos.sale.view')
            ->applyTo(Sale::query()->where('customer_id', $partyId), Scope::LOCATION);
        $totals = $visible()->where('status', Sale::COMPLETED)->groupBy('currency')->orderBy('currency')
            ->selectRaw('currency, sum(total_minor) as total')->get();
        $recent = $visible()->orderByDesc('sold_at')->limit($limit)->get();

        return [
            'count' => $visible()->where('status', Sale::COMPLETED)->count(),
            'totals' => $totals->map(fn ($row) => ['currency' => $row->currency, 'total_minor' => (string) $row->total])->values()->all(),
            'last_sold_at' => $recent->first()?->sold_at?->toIso8601String(),
            'recent' => $recent->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'receipt_number' => $sale->receipt_number,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'currency' => $sale->currency,
                'total_minor' => (string) $sale->total_minor,
                'status' => $sale->status,
            ])->values()->all(),
        ];
    }
}
