<?php

namespace Modules\Salon\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ShowSettingsRequest;
use Modules\Salon\Http\Requests\UpdateSettingsRequest;
use Modules\Salon\Models\Setting;
use Modules\Salon\Support\Catalogue;
use Modules\Salon\Support\Settings;

/** SAL-10, SAL-11, SAL-14, SAL-16, SAL-26: a company's salon settings. */
class SettingsController
{
    public function __construct(private readonly Catalogue $catalogue) {}

    public function show(ShowSettingsRequest $request, Company $company): JsonResponse
    {
        return response()->json(['data' => Settings::for($company->id)]);
    }

    public function update(UpdateSettingsRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();

        // The tip and fee items are items of this company's catalogue (service-like, never stock).
        foreach (['tip_item_id', 'fee_item_id'] as $field) {
            if (! empty($data[$field])) {
                $this->catalogue->item($company->id, $data[$field], $field);
            }
        }

        $setting = Setting::query()->where('company_id', $company->id)->first() ?? new Setting(['company_id' => $company->id]);
        $setting->fill($data)->save();
        // The till's prepaid-value payment method exists once the company is set up.
        $this->catalogue->creditMethod($company->id);

        return response()->json(['data' => Settings::for($company->id)]);
    }
}
