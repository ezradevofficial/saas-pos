<?php

namespace Modules\Pharmacy\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Pharmacy\Models\LotBalance;
use Modules\Pharmacy\Models\LotMovement;

/**
 * Stock by lot and location as the lists read it (PHA-05, PHA-24): balances
 * joined to their lot's state in the lot's own company day and near-expiry
 * window (the same rules as CompanyDays::state, in SQL so lists filter and
 * sort without loading rows).
 */
final class StockQuery
{
    public const STATES = ['expired', 'recalled', 'quarantined', 'near_expiry', 'active'];

    /** The company's day of a lot (a SQL expression over `pharmacy_lots`). */
    public static function today(): string
    {
        return "(now() at time zone coalesce((select c.timezone from companies c where c.id = pharmacy_lots.company_id), 'UTC'))::date";
    }

    /** The company's near-expiry window in days (its setting, else the default 90). */
    public static function window(): string
    {
        return 'coalesce((select s.near_expiry_days from pharmacy_settings s where s.company_id = pharmacy_lots.company_id), 90)';
    }

    /** @return Builder<LotBalance> balances with their relations; empty balances left out unless asked */
    public static function balances(bool $withEmpty = false): Builder
    {
        $query = LotBalance::query()->with(['lot', 'item', 'location']);

        return $withEmpty ? $query : $query->where('pharmacy_lot_balances.qty', '>', 0);
    }

    /** Only balances whose lot is in $state today. */
    public static function inState(Builder $query, string $state): Builder
    {
        $today = self::today();
        $window = self::window();

        return $query->whereHas('lot', fn (Builder $lot) => match ($state) {
            'expired' => $lot->whereRaw("pharmacy_lots.expires_on < {$today}"),
            'recalled' => $lot->whereRaw("pharmacy_lots.expires_on >= {$today}")->whereNotNull('pharmacy_lots.recall_id'),
            'quarantined' => $lot->whereRaw("pharmacy_lots.expires_on >= {$today}")->whereNull('pharmacy_lots.recall_id')->where('pharmacy_lots.status', 'quarantined'),
            'near_expiry' => $lot->whereRaw("pharmacy_lots.expires_on >= {$today} and pharmacy_lots.expires_on <= {$today} + {$window}")->whereNull('pharmacy_lots.recall_id')->where('pharmacy_lots.status', 'active'),
            default => $lot->whereRaw("pharmacy_lots.expires_on > {$today} + {$window}")->whereNull('pharmacy_lots.recall_id')->where('pharmacy_lots.status', 'active'),
        });
    }

    /** Only balances whose lot expired or expires within $days (null: the company's own window). */
    public static function expiringWithin(Builder $query, ?int $days): Builder
    {
        $today = self::today();
        $limit = $days === null ? self::window() : (string) $days;

        return $query->whereHas('lot', fn (Builder $lot) => $lot->whereRaw("pharmacy_lots.expires_on <= {$today} + {$limit}"));
    }

    /** With the time the lot last sold at the balance's place (`last_sold_at`), and only those that did not sell for $days days. */
    public static function slow(Builder $query, int $days): Builder
    {
        $sold = LotMovement::query()->selectRaw('max(occurred_at)')
            ->whereColumn('pharmacy_lot_movements.lot_id', 'pharmacy_lot_balances.lot_id')
            ->whereColumn('pharmacy_lot_movements.location_id', 'pharmacy_lot_balances.location_id')
            ->where('pharmacy_lot_movements.kind', LotMovement::SALE);

        return $query->addSelect('pharmacy_lot_balances.*')->selectSub($sold, 'last_sold_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pharmacy_lot_movements as recent')
                ->whereColumn('recent.lot_id', 'pharmacy_lot_balances.lot_id')
                ->whereColumn('recent.location_id', 'pharmacy_lot_balances.location_id')
                ->where('recent.kind', LotMovement::SALE)
                ->whereRaw("recent.occurred_at >= now() - (? * interval '1 day')", [$days]));
    }
}
