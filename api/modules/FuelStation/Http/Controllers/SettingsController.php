<?php

namespace Modules\FuelStation\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\FuelSettingsRequest;
use Modules\FuelStation\Models\Settings;

/** FUEL-03: a company's fuel settings (the tolerances). Edits are audited by the model (`fuel.settings.*`, AUD-01). */
class SettingsController
{
    public function __construct(private readonly Present $present) {}

    public function show(FuelSettingsRequest $request, Company $company): JsonResponse
    {
        return response()->json(['data' => $this->present->settings(Settings::for($company->id))]);
    }

    public function update(FuelSettingsRequest $request, Company $company): JsonResponse
    {
        $settings = Settings::query()->where('company_id', $company->id)->first() ?? new Settings(['company_id' => $company->id, ...Settings::defaults()]);
        $settings->fill($request->validated())->save();

        return response()->json(['data' => $this->present->settings($settings->refresh())]);
    }
}
