<?php

namespace App\Core\Onboarding\Http\Controllers;

use App\Core\Onboarding\Assisted\AssistedOnboarding;
use App\Core\Onboarding\Http\Requests\AssistedTenantsRequest;
use App\Core\Onboarding\Http\Requests\CreateAssistedTenantRequest;
use Illuminate\Http\JsonResponse;

/** ONB-08: tenants created by staff for customers, handed over by invitation. */
class AssistedTenantController
{
    public function __construct(private readonly AssistedOnboarding $assisted) {}

    /** POST platform/tenants: 201, or 200 with the tenant already made for the same contact (a retry). */
    public function store(CreateAssistedTenantRequest $request): JsonResponse
    {
        $result = $this->assisted->create($request->staff(), $request->validated());

        return response()->json(['data' => $result], $result['existing'] ? 200 : 201);
    }

    /** POST platform/tenants/{tenant}/reinvite: a new Owner invitation while not handed over. */
    public function reinvite(AssistedTenantsRequest $request, string $tenant): JsonResponse
    {
        return response()->json(['data' => $this->assisted->reinvite($request->staff(), $tenant)]);
    }

    /** GET platform/tenants/awaiting-handover: assisted tenants not handed over after the report period. */
    public function awaiting(AssistedTenantsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->assisted->awaitingHandover((int) config('onboarding.handover_report_days', 30))]);
    }
}
