<?php

namespace App\Core\Workflow\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Calendar\BusinessHours;
use App\Core\Workflow\Http\Requests\BusinessHoursRequest;
use App\Core\Workflow\Http\Requests\UpdateBusinessHoursRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * WF-09, APR-05: a company's working hours, the hours stage time limits
 * and escalations count (Monday to Friday 08:00-17:00 until set), in the
 * company's time zone. Changes are audited (`core.business_hours.*`).
 */
class BusinessHoursController
{
    public function show(BusinessHoursRequest $request, Company $company): JsonResponse
    {
        return $this->present($company);
    }

    public function update(UpdateBusinessHoursRequest $request, Company $company): JsonResponse
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $company) {
            $row = BusinessHours::query()->where('company_id', $company->id)->lockForUpdate()->first()
                ?? new BusinessHours(['company_id' => $company->id]);
            $row->fill(['hours' => $request->hours()])->save();
        });

        return $this->present($company);
    }

    private function present(Company $company): JsonResponse
    {
        $row = BusinessHours::query()->where('company_id', $company->id)->first();

        return new JsonResponse(['data' => [
            'company_id' => $company->id,
            'timezone' => $company->timezone,
            'hours' => $row?->hours ?? BusinessCalendar::DEFAULT_HOURS,
            'is_default' => $row === null,
            'updated_at' => $row?->updated_at?->toIso8601ZuluString(),
        ]]);
    }
}
