<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Modules\Hotel\Http\Requests\HotelSettingsRequest;
use Modules\Hotel\Http\Resources\SettingsResource;
use Modules\Hotel\Models\Settings;

/** HOT-02: a company's hotel settings. Edits are audited by the model (`hotel.settings.*`, AUD-01). */
class SettingsController
{
    public function show(HotelSettingsRequest $request, Company $company): SettingsResource
    {
        return SettingsResource::make(Settings::for($company->id));
    }

    public function update(HotelSettingsRequest $request, Company $company): SettingsResource
    {
        $settings = Settings::query()->where('company_id', $company->id)->first() ?? new Settings(['company_id' => $company->id, ...Settings::defaults()]);
        $settings->fill($request->validated())->save();

        // PUT answers 200 whether the company had settings of its own yet or not.
        $settings->refresh()->wasRecentlyCreated = false;

        return SettingsResource::make($settings);
    }
}
