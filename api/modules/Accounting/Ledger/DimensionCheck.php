<?php

namespace Modules\Accounting\Ledger;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Dimensions\Project;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;

/**
 * ACC-21: dimensions, branches and parties named on lines belong to the
 * company (a shared party too) and are active. Ids are looked up under
 * row-level security, so another tenant's never pass.
 */
final class DimensionCheck
{
    public const KEYS = ['cost_centre_id', 'department_id', 'project_id', 'branch_id', 'party_id'];

    /** @param list<array<string, ?string>> $rows each row's dimension ids by key */
    public static function assert(Company $company, array $rows, string $prefix = 'lines'): void
    {
        $checks = [
            'cost_centre_id' => fn (array $ids) => CostCentre::query()->where('company_id', $company->id)->whereNull('archived_at')->whereKey($ids)->pluck('id'),
            'department_id' => fn (array $ids) => Department::query()->where('company_id', $company->id)->whereNull('archived_at')->whereKey($ids)->pluck('id'),
            'project_id' => fn (array $ids) => Project::query()->where('company_id', $company->id)->whereNull('archived_at')->whereKey($ids)->pluck('id'),
            'branch_id' => fn (array $ids) => Branch::query()->where('company_id', $company->id)->whereKey($ids)->pluck('id'),
            'party_id' => fn (array $ids) => Party::query()->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->whereKey($ids)->pluck('id'),
        ];

        foreach ($checks as $key => $check) {
            $ids = array_values(array_unique(array_filter(array_map(fn (array $row) => $row[$key] ?? null, $rows))));

            if ($ids === []) {
                continue;
            }

            $found = $check($ids)->map(fn ($id) => (string) $id)->all();

            foreach ($rows as $index => $row) {
                if (($row[$key] ?? null) !== null && ! in_array($row[$key], $found, true)) {
                    throw LedgerError::make('dimension_unknown', field: "{$prefix}.{$index}.{$key}");
                }
            }
        }
    }
}
