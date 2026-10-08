<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\MasterData\Dimensions\CostCentre;

/** APR-02 `cost_centre_owner`: the owner of the document's cost centre (MD-05). */
class CostCentreOwnerResolver extends DimensionOwnerResolver
{
    public function key(): string
    {
        return 'cost_centre_owner';
    }

    public function label(): string
    {
        return 'approvals.approver_types.cost_centre_owner';
    }

    protected function reference(): string
    {
        return 'core.cost_centre';
    }

    protected function model(): string
    {
        return CostCentre::class;
    }
}
