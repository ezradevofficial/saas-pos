<?php

namespace Modules\FixedAssets\Assets;

use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\Tenancy\Models\Location;
use Modules\FixedAssets\Support\FaError;

/**
 * TEN-04, MD-05: where an asset may sit (a location of the tenant, not
 * archived, of the asset's company when it has one) and the dimensions of
 * that company, read under RLS: another tenant's id is never found.
 */
class Places
{
    /** @return array{company_id: string, branch_id: string, location_id: string} */
    public function location(string $locationId, ?string $companyId = null, string $field = 'location_id'): array
    {
        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.archived_at']);

        if ($row === null || $row->archived_at !== null) {
            throw FaError::make('location_unknown', field: $field);
        }

        if ($companyId !== null && (string) $row->company_id !== $companyId) {
            throw FaError::make('location_other_company', field: $field);
        }

        return ['company_id' => (string) $row->company_id, 'branch_id' => (string) $row->branch_id, 'location_id' => $locationId];
    }

    public function costCentre(?string $id, string $companyId, string $field = 'cost_centre_id'): ?string
    {
        if ($id === null) {
            return null;
        }

        $row = CostCentre::query()->whereKey($id)->whereNull('archived_at')->first();

        if ($row === null || $row->company_id !== $companyId) {
            throw FaError::make('dimension_other_company', field: $field);
        }

        return $id;
    }

    public function department(?string $id, string $companyId, string $field = 'department_id'): ?string
    {
        if ($id === null) {
            return null;
        }

        $row = Department::query()->whereKey($id)->whereNull('archived_at')->first();

        if ($row === null || $row->company_id !== $companyId) {
            throw FaError::make('dimension_other_company', field: $field);
        }

        return $id;
    }
}
