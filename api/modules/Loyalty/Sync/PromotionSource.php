<?php

namespace Modules\Loyalty\Sync;

use App\Core\MasterData\Parties\Party;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Promotions\Promotions;

/**
 * LOY-11, NFR-04: the promotions the till evaluates offline: the active
 * ones of its company that may apply at its branch, in the engine's shape
 * (current version), with their coupon codes and uses left (`coupons`),
 * plus one row per customer group a promotion names (`group:{tag}`) listing
 * the customers holding that tag (core party tags), so customer-group
 * promotions work offline. Customers of other companies are not listed.
 */
class PromotionSource implements SnapshotSource
{
    public function key(): string
    {
        return 'loyalty_promotions';
    }

    public function module(): string
    {
        return LoyaltyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $promotions = Promotion::query()
            ->where('company_id', $scope->companyId())
            ->where('status', Promotion::ACTIVE)
            ->orderBy('id')
            ->get()
            ->filter(fn (Promotion $p) => ($p->definition['branch_ids'] ?? []) === [] || in_array($scope->branch->id, $p->definition['branch_ids'], true));
        $coupons = Coupon::query()->whereIn('promotion_id', $promotions->modelKeys())->whereNull('archived_at')->orderBy('code')->get()->groupBy('promotion_id');
        $rows = [];
        $tags = [];

        foreach ($promotions as $promotion) {
            $definition = [...$promotion->definition, 'type' => $promotion->type, 'priority' => $promotion->priority, 'requires_coupon' => $promotion->requires_coupon];
            $rows[] = [
                'id' => $promotion->id,
                'kind' => 'promotion',
                'name' => $promotion->name,
                ...Promotions::engineShape($promotion->id, $promotion->version, $definition, $coupons->get($promotion->id)),
                'coupons' => ($coupons->get($promotion->id) ?? collect())->map(fn (Coupon $c) => ['code' => $c->code, 'remaining' => $c->remaining()])->values()->all(),
            ];
            $tags = [...$tags, ...($promotion->definition['customer_tags'] ?? [])];
        }

        foreach (array_values(array_unique($tags)) as $tag) {
            $rows[] = [
                'id' => 'group:'.$tag,
                'kind' => 'group',
                'tag' => $tag,
                'customer_ids' => Party::query()
                    ->whereRaw("roles @> array['customer']::text[]")
                    ->whereRaw('tags @> array[?]::text[]', [$tag])
                    ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $scope->companyId()))
                    ->whereNull('archived_at')
                    ->orderBy('id')->pluck('id')->all(),
            ];
        }

        return $rows;
    }
}
