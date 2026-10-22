<?php

namespace Modules\HR\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Sensitive;

/**
 * HR-24, MKT-04: an employee on the attendance roster: id, display name
 * and enrolment reference, nothing else (no sensitive group, HR-09). The
 * reader's `hr_employee` field rules (RBAC-05) hide the reference, or the
 * name when they hide the first or last name.
 *
 * @mixin Employee
 */
class ConnectorEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;

        return HidesFields::apply($request, Sensitive::RESOURCE, [
            'id' => $employee->id,
            'name' => $employee->displayName(),
            'biometric_ref' => $employee->biometric_ref,
        ], ['name' => ['first_name', 'last_name']]);
    }
}
