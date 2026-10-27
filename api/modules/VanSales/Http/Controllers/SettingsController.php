<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\ShowSettingsRequest;
use Modules\VanSales\Http\Requests\UpdateSettingsRequest;
use Modules\VanSales\Models\Setting;
use Modules\VanSales\Support\Settings;

/** VAN-24, VAN-11: a company's GPS and credit settings. */
class SettingsController
{
    public function show(ShowSettingsRequest $request, string $company): JsonResponse
    {
        return response()->json(['data' => Settings::for(Company::query()->findOrFail($company)->id)]);
    }

    public function update(UpdateSettingsRequest $request, string $company): JsonResponse
    {
        $id = Company::query()->findOrFail($company)->id;
        $setting = Setting::query()->where('company_id', $id)->first() ?? new Setting(['company_id' => $id]);
        $setting->fill($request->validated())->save();

        return response()->json(['data' => Settings::for($id)]);
    }
}
