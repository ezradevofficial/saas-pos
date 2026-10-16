<?php

namespace Modules\FixedAssets\Reports;

use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Models\Asset;

/**
 * FA-26: a company's reports, in base minor units: depreciation charged in
 * a period (posted runs and disposals' last charges) by category, branch
 * or cost centre; additions (assets in service in the period) and
 * disposals (posted in it); and the net book value by category of the
 * assets in use now.
 */
class Reports
{
    public const GROUPS = ['category' => ['fa_categories', 'category_id'], 'branch' => ['branches', 'branch_id'], 'cost_centre' => ['cost_centres', 'cost_centre_id']];

    /** @return list<array{id: ?string, name: ?string, amount: int}> */
    public function depreciation(Company $company, string $from, string $to, string $group): array
    {
        [$table, $column] = self::GROUPS[$group];
        $runs = DB::table('fa_depreciation_lines as l')->join('fa_depreciation_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.company_id', $company->id)->where('r.status', 'posted')->whereBetween('l.to_date', [$from, $to])
            ->selectRaw("l.{$column} as id, sum(l.amount_minor) as amount")->groupBy("l.{$column}")->pluck('amount', 'id')->all();
        $disposals = DB::table('fa_disposals as d')->join('fa_assets as a', 'a.id', '=', 'd.asset_id')
            ->where('d.company_id', $company->id)->where('d.status', 'posted')->whereBetween('d.disposal_date', [$from, $to])
            ->selectRaw("a.{$column} as id, sum(d.depreciation_minor) as amount")->groupBy("a.{$column}")->pluck('amount', 'id')->all();
        $totals = [];

        foreach ([$runs, $disposals] as $rows) {
            foreach ($rows as $id => $amount) {
                $totals[(string) $id] = ($totals[(string) $id] ?? 0) + (int) $amount;
            }
        }

        $names = DB::table($table)->whereIn('id', array_filter(array_keys($totals)))->pluck('name', 'id')->all();
        $rows = [];

        foreach ($totals as $id => $amount) {
            if ($amount !== 0) {
                $rows[] = ['id' => $id === '' ? null : $id, 'name' => $id === '' ? null : ($names[$id] ?? null), 'amount' => $amount];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /** @return array{additions: list<array<string, mixed>>, disposals: list<array<string, mixed>>} */
    public function movements(Company $company, string $from, string $to): array
    {
        $additions = Asset::query()->with('category')->where('company_id', $company->id)->whereIn('status', [Asset::ACTIVE, Asset::DISPOSED])
            ->whereNull('archived_at')->whereBetween('in_service_on', [$from, $to])->orderBy('in_service_on')->orderBy('number')->get()
            ->map(fn (Asset $asset) => [
                'asset' => ['id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name],
                'category' => $asset->category?->name,
                'date' => $asset->in_service_on?->toDateString(),
                'cost' => (int) $asset->base_cost_minor,
                'opening' => (bool) $asset->opening,
            ])->all();

        $disposals = DB::table('fa_disposals as d')->join('fa_assets as a', 'a.id', '=', 'd.asset_id')->leftJoin('fa_categories as c', 'c.id', '=', 'a.category_id')
            ->where('d.company_id', $company->id)->where('d.status', 'posted')->whereBetween('d.disposal_date', [$from, $to])
            ->orderBy('d.disposal_date')->orderBy('d.number')
            ->get(['d.id', 'd.number', 'd.kind', 'd.disposal_date', 'd.cost_minor', 'd.accumulated_minor', 'd.impairment_minor', 'd.book_value_minor', 'd.base_proceeds_minor', 'd.gain_minor', 'a.id as asset_id', 'a.number as asset_number', 'a.name as asset_name', 'c.name as category'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'number' => $row->number,
                'kind' => $row->kind,
                'asset' => ['id' => $row->asset_id, 'number' => $row->asset_number, 'name' => $row->asset_name],
                'category' => $row->category,
                'date' => substr((string) $row->disposal_date, 0, 10),
                'cost' => (int) $row->cost_minor,
                'accumulated' => (int) $row->accumulated_minor + (int) $row->impairment_minor,
                'book_value' => (int) $row->book_value_minor,
                'proceeds' => (int) $row->base_proceeds_minor,
                'gain' => (int) $row->gain_minor,
            ])->all();

        return ['additions' => $additions, 'disposals' => $disposals];
    }

    /** @return list<array{category: array{id: ?string, name: ?string}, count: int, cost: int, accumulated: int, impairment: int, book_value: int}> */
    public function netBookValue(Company $company): array
    {
        return DB::table('fa_assets as a')->leftJoin('fa_categories as c', 'c.id', '=', 'a.category_id')
            ->where('a.company_id', $company->id)->where('a.status', Asset::ACTIVE)->whereNull('a.archived_at')
            ->groupBy('a.category_id', 'c.name', 'c.code')->orderBy('c.code')
            ->selectRaw('a.category_id, c.name, count(*) as assets, sum(a.book_cost_minor) as cost, sum(a.accumulated_minor) as accumulated, sum(a.impairment_minor) as impairment')
            ->get()
            ->map(fn ($row) => [
                'category' => ['id' => $row->category_id, 'name' => $row->name],
                'count' => (int) $row->assets,
                'cost' => (int) $row->cost,
                'accumulated' => (int) $row->accumulated,
                'impairment' => (int) $row->impairment,
                'book_value' => (int) $row->cost - (int) $row->accumulated - (int) $row->impairment,
            ])->all();
    }
}
