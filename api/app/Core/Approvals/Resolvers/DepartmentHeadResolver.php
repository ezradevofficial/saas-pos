<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\MasterData\Dimensions\Department;

/** APR-02 `department_head`: the owner of the document's department (MD-05). */
class DepartmentHeadResolver extends DimensionOwnerResolver
{
    public function key(): string
    {
        return 'department_head';
    }

    public function label(): string
    {
        return 'approvals.approver_types.department_head';
    }

    protected function reference(): string
    {
        return 'core.department';
    }

    protected function model(): string
    {
        return Department::class;
    }
}
