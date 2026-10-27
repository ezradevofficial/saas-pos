<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\Models\Company;
use Modules\Pharmacy\Http\Requests\CompanySettingsRequest;
use Modules\Pharmacy\Http\Resources\SettingsResource;
use Modules\Pharmacy\Models\Settings;

/** PHA-02: a company's pharmacy settings. */
class SettingsController
{
    public function __construct(private readonly Auditor $auditor) {}

    public function show(CompanySettingsRequest $request, Company $company): SettingsResource
    {
        return SettingsResource::make(Settings::for($company->id));
    }

    public function update(CompanySettingsRequest $request, Company $company): SettingsResource
    {
        $settings = Settings::query()->where('company_id', $company->id)->first() ?? new Settings(['company_id' => $company->id, ...Settings::defaults()]);
        $before = $settings->exists ? $settings->only(array_keys($request->validated())) : null;
        $settings->fill($request->validated())->save();
        $this->auditor->record($before === null ? 'pharmacy.settings.create' : 'pharmacy.settings.update', $settings, $before, $settings->only(array_keys($request->validated())));

        // PUT answers 200 whether the company had settings of its own yet or not.
        $settings->refresh()->wasRecentlyCreated = false;

        return SettingsResource::make($settings);
    }
}
