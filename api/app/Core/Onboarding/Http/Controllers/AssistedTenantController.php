<?php

namespace App\Core\Onboarding\Http\Controllers;

use App\Core\Onboarding\Assisted\AssistedOnboarding;
use App\Core\Onboarding\Http\Requests\CreateAssistedTenantRequest;
use Illuminate\Http\JsonResponse;

/** ONB-08: POST platform/tenants, a tenant created for a customer, handed over by invitation. */
class AssistedTenantController
{
    public function store(CreateAssistedTenantRequest $request, AssistedOnboarding $assisted): JsonResponse
    {
        return response()->json(['data' => $assisted->create($request->staff(), $request->validated())], 201);
    }
}
