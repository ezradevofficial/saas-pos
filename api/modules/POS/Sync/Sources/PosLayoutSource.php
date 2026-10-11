<?php

namespace Modules\POS\Sync\Sources;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigResolver;
use App\Core\Configuration\Place;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\POS\Layout\PosLayout;
use Modules\POS\Layout\PosLayoutCatalogue;
use Modules\POS\PosServiceProvider;
use Throwable;

/**
 * LAY-05, NFR-04: the sell screen's layout at the till's location (one
 * row, id `layout`): the published `pos_layout` of the location, else its
 * branch's, its company's or the tenant's (else the defaults), merged with
 * what the till holds (LAY-07: new categories last, deleted ones skipped).
 *
 * With products ordered by best-sellers, `best_sellers` lists the items
 * sold most often at the location over the last 30 days (sales, not
 * quantities), most first; the till orders the rest by name. The ranking
 * is cached per tenant and location for 30 minutes, apart from the
 * per-device snapshot cache: every till of a location shares one query.
 */
class PosLayoutSource implements SnapshotSource
{
    /** How long a location's best-sellers are kept (NFR-05). */
    public const BEST_SELLERS_TTL_SECONDS = 1800;

    public function key(): string
    {
        return 'pos_layout';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $kind = app(ConfigKinds::class)->find(PosLayout::KEY);

        if ($kind === null) {
            return [];
        }

        $place = new Place($scope->companyId(), $scope->branch->id, $scope->location->id);
        $version = app(ConfigResolver::class)->publishedAt($kind, ConfigKind::DEFAULT_KEY, $place);
        $catalogue = PosLayoutCatalogue::forDevice($scope);
        $layout = null;

        if ($version !== null) {
            try {
                $layout = PosLayout::merge(is_array($version->payload) ? $version->payload : throw new \UnexpectedValueException('The payload is not an object.'), $catalogue);
            } catch (Throwable $e) {
                // Resolving never fails the till: the defaults apply (ADR 010).
                Log::warning('Published POS layout could not be read; using the defaults.', ['version_id' => $version->id, 'error' => $e->getMessage()]);
                $version = null;
            }
        }

        $layout ??= PosLayout::merge(PosLayout::DEFAULTS, $catalogue);
        $source = $version === null ? null : ['type' => $version->document->scope_type, 'id' => $version->document->scope_id];

        return [[
            'id' => 'layout',
            'layout' => $layout,
            'scope' => $source,
            'version' => $version?->version,
            'best_sellers' => $layout['products']['order'] === 'best_sellers' ? $this->bestSellers($scope) : [],
        ]];
    }

    /** @return list<string> item ids, most sold first */
    private function bestSellers(DeviceScope $scope): array
    {
        return Cache::remember(
            self::bestSellersKey($scope->tenantId, $scope->location->id),
            self::BEST_SELLERS_TTL_SECONDS,
            fn () => $this->rankBestSellers($scope),
        );
    }

    public static function bestSellersKey(string $tenantId, string $locationId): string
    {
        return "pos.best_sellers.{$tenantId}.{$locationId}";
    }

    /** @return list<string> */
    private function rankBestSellers(DeviceScope $scope): array
    {
        return DB::connection(TenantContext::CONNECTION)->table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->where('pos_sales.location_id', $scope->location->id)
            ->where('pos_sales.status', 'completed')
            ->where('pos_sales.sold_at', '>=', $scope->at->subDays(PosLayout::BEST_SELLER_DAYS)->startOfDay())
            ->groupBy('pos_sale_lines.item_id')
            ->orderByRaw('count(distinct pos_sale_lines.sale_id) desc')
            ->orderBy('pos_sale_lines.item_id')
            ->limit(PosLayout::BEST_SELLER_LIMIT)
            ->pluck('pos_sale_lines.item_id')
            ->all();
    }
}
