<?php

namespace Modules\Loyalty\Promotions;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Models\PromotionVersion;

/**
 * LOY-08, LOY-12: promotions and their versions. Every saved change
 * (definition, priority, status, coupon requirement) raises the version
 * and stores it with when it took effect, so a sale is re-evaluated with
 * the version in force when it was sold. Audited (AUD-01).
 *
 * The definition holds the engine's fields (PromotionEngine): percent,
 * amount_minor, currency, buy_qty, get_qty, targets, get_targets,
 * components, min_spend_minor, branch_ids, customer_tags, schedule.
 */
class Promotions
{
    /** Definition keys, in a stable order (the version's payload). */
    public const FIELDS = ['percent', 'amount_minor', 'currency', 'buy_qty', 'get_qty', 'targets', 'get_targets', 'components', 'min_spend_minor', 'branch_ids', 'customer_tags', 'schedule'];

    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $values validated (PromotionRequest) */
    public function create(array $values, User $by): Promotion
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($values, $by) {
            $promotion = Promotion::create([
                'company_id' => $values['company_id'],
                'name' => $values['name'],
                'description' => $values['description'] ?? null,
                'type' => $values['type'],
                'status' => $values['status'] ?? Promotion::DRAFT,
                'priority' => (int) ($values['priority'] ?? 0),
                'requires_coupon' => (bool) ($values['requires_coupon'] ?? false),
                'definition' => self::definition($values),
                'version' => 1,
                'created_by' => $by->id,
                'updated_by' => $by->id,
            ]);
            $this->version($promotion, $by);
            $this->auditor->record('loyalty.promotion.create', $promotion, null, self::audited($promotion), ['user_id' => $by->id]);

            return $promotion->refresh();
        });
    }

    /** @param array<string, mixed> $values validated (PromotionRequest; the type cannot change) */
    public function update(Promotion $promotion, array $values, User $by): Promotion
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($promotion, $values, $by) {
            $promotion = Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $before = self::audited($promotion);
            $promotion->fill([
                'name' => $values['name'] ?? $promotion->name,
                'description' => array_key_exists('description', $values) ? $values['description'] : $promotion->description,
                'priority' => (int) ($values['priority'] ?? $promotion->priority),
                'requires_coupon' => (bool) ($values['requires_coupon'] ?? $promotion->requires_coupon),
                'definition' => self::definition([...$promotion->definition, ...array_intersect_key($values, array_flip(self::FIELDS))]),
                'updated_by' => $by->id,
            ]);

            return $this->saveVersioned($promotion, $before, 'loyalty.promotion.update', $by);
        });
    }

    public function setStatus(Promotion $promotion, string $status, User $by): Promotion
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($promotion, $status, $by) {
            $promotion = Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $before = self::audited($promotion);
            $promotion->fill([
                'status' => $status,
                'archived_at' => $status === Promotion::ARCHIVED ? CarbonImmutable::now() : null,
                'updated_by' => $by->id,
            ]);

            return $this->saveVersioned($promotion, $before, $status === Promotion::ARCHIVED ? 'loyalty.promotion.archive' : 'loyalty.promotion.activate', $by);
        });
    }

    /**
     * LOY-12: the promotions of a company as they were at $at, in the
     * engine's shape (active versions only), with their coupon codes.
     *
     * @return list<array<string, mixed>>
     */
    public function inForce(string $companyId, CarbonImmutable $at): array
    {
        $versions = PromotionVersion::query()
            ->whereIn('promotion_id', Promotion::query()->where('company_id', $companyId)->select('id'))
            ->where('effective_at', '<=', $at)
            ->orderBy('promotion_id')->orderByDesc('version')
            ->get()->unique('promotion_id');

        $codes = Coupon::query()->whereIn('promotion_id', $versions->pluck('promotion_id'))->whereNull('archived_at')->get()->groupBy('promotion_id');

        return $versions->filter(fn (PromotionVersion $v) => $v->status === Promotion::ACTIVE)
            ->map(fn (PromotionVersion $v) => self::engineShape($v->promotion_id, $v->version, $v->definition, $codes->get($v->promotion_id)))
            ->values()->all();
    }

    /**
     * LOY-12, H2: a sale evaluated as the till should have: the promotion
     * versions in force at its time, its customer's groups (party tags) now,
     * its coupons and the branch's local time.
     *
     * @param  list<array{id: string, item_id: string, qty: string, unit_price_minor: string}>  $lines
     * @param  list<string>  $coupons
     */
    public function evaluateSale(string $companyId, string $branchId, ?string $partyId, string $currency, CarbonImmutable $soldAt, array $lines, array $coupons): array
    {
        $party = $partyId === null ? null : Party::query()->find($partyId);

        return PromotionEngine::evaluate(
            self::engineLines($lines),
            [
                'currency' => $currency, 'branch_id' => $branchId, 'customer_tags' => array_values(array_map('strval', (array) ($party?->tags ?? []))),
                'coupons' => array_values(array_unique(array_map(fn ($code) => PromotionEngine::normaliseCode((string) $code), $coupons))),
                'local' => self::localTime($branchId, $soldAt),
            ],
            $this->inForce($companyId, $soldAt),
        );
    }

    /**
     * H2: the POS discount verifier of `loyalty.promotion`: per line, the
     * discount the promotions give (core DiscountSources).
     *
     * @param  array<string, mixed>  $sale
     * @return array<string, string>
     */
    public function verify(array $sale): array
    {
        $lines = array_values(array_filter((array) ($sale['lines'] ?? []), fn ($line) => is_array($line) && isset($line['id'], $line['item_id'], $line['qty'], $line['unit_price_minor'])));
        $coupons = $sale['extensions']['loyalty']['coupons'] ?? [];
        $result = $this->evaluateSale((string) $sale['company_id'], (string) $sale['branch_id'], $sale['customer_id'] ?? null, (string) $sale['currency'],
            CarbonImmutable::parse((string) $sale['sold_at']), $lines, is_array($coupons) ? array_filter($coupons, 'is_string') : []);

        return array_column($result['lines'], 'discount_minor', 'line_id');
    }

    /**
     * A promotion's definition as the engine (and the till) read it.
     *
     * @param  array<string, mixed>  $definition  a version's definition (with type, priority, requires_coupon)
     * @param  Collection<int, Coupon>|null  $coupons
     * @return array<string, mixed>
     */
    public static function engineShape(string $id, int $version, array $definition, ?Collection $coupons): array
    {
        return [
            'id' => $id,
            'version' => $version,
            'type' => $definition['type'],
            'priority' => (int) ($definition['priority'] ?? 0),
            ...array_intersect_key($definition, array_flip(self::FIELDS)),
            'coupon_codes' => ($definition['requires_coupon'] ?? false) ? ($coupons ?? collect())->pluck('code')->values()->all() : null,
        ];
    }

    /**
     * LOY-13: the engine's lines for a cart: each item's categories with
     * their ancestors (a promotion on "Drinks" covers "Soft drinks"), as
     * the till computes them from its synced categories.
     *
     * @param  list<array{id: string, item_id: string, qty: string, unit_price_minor: string}>  $lines
     * @return list<array<string, mixed>>
     */
    public static function engineLines(array $lines): array
    {
        $items = Item::query()->whereIn('id', array_column($lines, 'item_id'))->pluck('category_id', 'id');
        $parents = ItemCategory::query()->pluck('parent_id', 'id');
        $chain = function (?string $category) use ($parents): array {
            $ids = [];

            while ($category !== null && ! in_array($category, $ids, true)) {
                $ids[] = $category;
                $category = $parents[$category] ?? null;
            }

            return $ids;
        };

        return array_map(fn (array $line) => [
            'id' => (string) $line['id'],
            'item_id' => (string) $line['item_id'],
            'category_ids' => $chain($items[$line['item_id']] ?? null),
            'qty' => (string) $line['qty'],
            'unit_price_minor' => (string) $line['unit_price_minor'],
        ], $lines);
    }

    /** The branch's local date, ISO weekday and time at $at (the branch's zone, else its company's). */
    public static function localTime(string $branchId, CarbonImmutable $at): array
    {
        $branch = Branch::query()->with('company')->find($branchId);
        $zone = $branch?->timezone ?: ($branch?->company?->timezone ?: 'UTC');
        $local = $at->setTimezone($zone);

        return ['date' => $local->format('Y-m-d'), 'weekday' => (int) $local->format('N'), 'time' => $local->format('H:i')];
    }

    private function saveVersioned(Promotion $promotion, array $before, string $action, User $by): Promotion
    {
        $after = self::audited($promotion);

        // jsonb keeps its own key order: compare by keys and values, not order.
        if ($after == $before) {
            return $promotion;
        }

        $promotion->version = $promotion->version + 1;
        $promotion->save();
        $this->version($promotion, $by);
        $this->auditor->record($action, $promotion, $before, [...$after, 'version' => $promotion->version], ['user_id' => $by->id]);

        return $promotion->refresh();
    }

    private function version(Promotion $promotion, User $by): void
    {
        PromotionVersion::create([
            'promotion_id' => $promotion->id,
            'version' => $promotion->version,
            'status' => $promotion->status,
            'definition' => [...$promotion->definition, 'type' => $promotion->type, 'priority' => $promotion->priority, 'requires_coupon' => $promotion->requires_coupon],
            'effective_at' => CarbonImmutable::now(),
            'created_by' => $by->id,
        ]);
    }

    /** @return array<string, mixed> */
    private static function definition(array $values): array
    {
        $definition = [];

        foreach (self::FIELDS as $field) {
            $definition[$field] = $values[$field] ?? null;
        }

        $definition['targets'] = ['items' => array_values($definition['targets']['items'] ?? []), 'categories' => array_values($definition['targets']['categories'] ?? [])];
        $definition['branch_ids'] = array_values($definition['branch_ids'] ?? []);
        $definition['customer_tags'] = array_values($definition['customer_tags'] ?? []);
        $definition['components'] = array_values(array_map(fn (array $c) => ['item_id' => $c['item_id'], 'qty' => (int) $c['qty']], $definition['components'] ?? []));
        $definition['schedule'] = [
            'starts_on' => $definition['schedule']['starts_on'] ?? null,
            'ends_on' => $definition['schedule']['ends_on'] ?? null,
            'days' => array_values(array_map('intval', $definition['schedule']['days'] ?? [])),
            'time_from' => $definition['schedule']['time_from'] ?? null,
            'time_to' => $definition['schedule']['time_to'] ?? null,
        ];

        foreach (['amount_minor', 'min_spend_minor'] as $money) {
            $definition[$money] = $definition[$money] === null ? null : (string) $definition[$money];
        }

        $definition['percent'] = $definition['percent'] === null ? null : (string) $definition['percent'];

        return $definition;
    }

    /** @return array<string, mixed> */
    private static function audited(Promotion $promotion): array
    {
        return [
            'name' => $promotion->name, 'description' => $promotion->description, 'status' => $promotion->status, 'priority' => $promotion->priority,
            'requires_coupon' => (bool) $promotion->requires_coupon, 'definition' => $promotion->definition,
        ];
    }
}
