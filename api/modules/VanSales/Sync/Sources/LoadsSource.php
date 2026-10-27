<?php

namespace Modules\VanSales\Sync\Sources;

use App\Core\MasterData\Items\Item;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Loads\Loads;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\LoadLine;
use Modules\VanSales\Support\Qty;
use Modules\VanSales\VanSalesServiceProvider;

/** VAN-07, VAN-08, NFR-04: the van's load sheets not yet confirmed, and those confirmed in the last 7 days. */
class LoadsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'van_sales_loads';
    }

    public function module(): string
    {
        return VanSalesServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $van = VanSource::vanOf($scope);

        if ($van === null) {
            return [];
        }

        $loads = Load::query()->where('van_id', $van->id)->where('state', '<>', 'cancelled')
            ->where(fn ($q) => $q->where('state', 'open')->orWhere('confirmed_at', '>=', $scope->at->subDays(7)))->orderBy('created_at')->orderBy('id')->get();
        $lines = LoadLine::query()->whereIn('load_id', $loads->pluck('id'))->orderBy('created_at')->orderBy('id')->get()->groupBy('load_id');
        $items = Item::query()->whereKey($lines->flatten()->pluck('item_id')->unique()->all())->pluck('name', 'id');
        $statuses = app(Loads::class)->statuses($loads);

        return $loads->map(fn (Load $load) => [
            'id' => $load->id,
            'run_id' => $load->run_id,
            'number' => $load->number,
            'status' => $statuses[$load->id],
            'lines' => ($lines[$load->id] ?? collect())->map(fn (LoadLine $line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item_name' => $items[$line->item_id] ?? null,
                'qty' => Qty::show((string) $line->qty),
                'qty_confirmed' => $line->qty_confirmed === null ? null : Qty::show((string) $line->qty_confirmed),
            ])->values()->all(),
        ])->values()->all();
    }
}
