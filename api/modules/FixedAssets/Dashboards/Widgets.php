<?php

namespace Modules\FixedAssets\Dashboards;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Carbon\CarbonImmutable;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\MaintenanceSchedule;

/**
 * LAY-01, FA-28: the module's dashboard sources: the net book value of
 * the assets the reader may value (KPI, the largest base currency when
 * companies differ, `complete` false then), maintenance due in 30 days
 * (list) and fully depreciated assets still in use (KPI count).
 */
class Widgets extends DashboardSource
{
    public const BOOK_VALUE = 'fixed_assets.book_value';

    public const MAINTENANCE_DUE = 'fixed_assets.maintenance_due';

    public const FULLY_DEPRECIATED = 'fixed_assets.fully_depreciated';

    public function __construct(private readonly string $key = self::BOOK_VALUE) {}

    public function key(): string
    {
        return $this->key;
    }

    public function module(): string
    {
        return FixedAssetsServiceProvider::MODULE;
    }

    public function label(): string
    {
        return 'fixed_assets.dashboards.'.substr($this->key, strlen('fixed_assets.'));
    }

    public function widgets(): array
    {
        return [$this->key === self::MAINTENANCE_DUE ? self::LIST : self::KPI];
    }

    public function permissions(): array
    {
        return [match ($this->key) {
            self::BOOK_VALUE => 'fixed_assets.value.view',
            self::MAINTENANCE_DUE => 'fixed_assets.maintenance.view',
            default => 'fixed_assets.asset.view',
        }];
    }

    public function rules(): array
    {
        return $this->key === self::MAINTENANCE_DUE ? ['limit' => ['sometimes', 'integer', 'between:1,20']] : [];
    }

    public function data(User $user, array $params): array
    {
        $visible = app(ScopeResolver::class)->visibleIds($user, $this->permissions()[0]);
        $assets = Asset::query()->where('status', Asset::ACTIVE)->whereNull('archived_at');
        $visible->applyTo($assets, Scope::LOCATION, 'location_id');

        if ($this->key === self::BOOK_VALUE) {
            $totals = $assets->selectRaw('base_currency, sum(book_cost_minor - accumulated_minor - impairment_minor) as value, count(*) as assets')
                ->groupBy('base_currency')->orderByRaw('sum(book_cost_minor - accumulated_minor - impairment_minor) desc')->get();
            $first = $totals->first();

            return ['kind' => 'money', 'value' => $first === null ? '0' : (string) $first->value, 'currency' => $first?->base_currency,
                'count' => (int) ($first?->assets ?? 0), 'complete' => $totals->count() <= 1, 'to' => '/fixed-assets/reports'];
        }

        if ($this->key === self::FULLY_DEPRECIATED) {
            $count = $assets->whereRaw('book_cost_minor - accumulated_minor - impairment_minor <= residual_minor')->where('method', '<>', 'none')->count();

            return ['kind' => 'number', 'value' => $count, 'to' => '/fixed-assets/assets'];
        }

        $until = CarbonImmutable::now()->addDays(30)->toDateString();
        $query = MaintenanceSchedule::query()->whereNull('archived_at')->where('basis', 'time')->where('next_due_on', '<=', $until)
            ->whereIn('asset_id', $assets->select('id'));
        $total = (clone $query)->count();
        $rows = $query->with('asset')->orderBy('next_due_on')->orderBy('id')->limit((int) ($params['limit'] ?? 5))->get();

        return [
            'kind' => 'number',
            'value' => $total,
            'rows' => $rows->map(fn (MaintenanceSchedule $schedule) => [
                'id' => $schedule->id,
                'title' => trim($schedule->asset?->number.' '.$schedule->asset?->name),
                'subtitle' => __('fixed_assets.dashboards.maintenance_row', ['name' => $schedule->name, 'date' => $schedule->next_due_on?->toDateString()]),
                'to' => '/fixed-assets/assets/'.$schedule->asset_id,
                'at' => $schedule->next_due_on?->toIso8601String(),
            ])->all(),
            'to' => '/fixed-assets/maintenance',
        ];
    }
}
