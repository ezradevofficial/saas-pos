<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\CRM\Http\Requests\Setup\SettingsRequest;
use Modules\CRM\Http\Resources\SetupResource;
use Modules\CRM\Setup\CompanySetup;
use Modules\CRM\Setup\Configuration;
use Modules\CRM\Support\CrmAccess;

/** CRM-01: a company's CRM settings (seeded with its pipeline and lists on first use). */
class SettingsController
{
    public function show(SettingsRequest $request, Company $company, CompanySetup $setup): JsonResponse
    {
        return $this->present($request, $company, $setup->ensure($company));
    }

    public function update(SettingsRequest $request, Company $company, Configuration $configuration): JsonResponse
    {
        return $this->present($request, $company, $configuration->updateSettings($company, $request->validated()));
    }

    private function present(SettingsRequest $request, Company $company, $settings): JsonResponse
    {
        $settings->load('defaultTeam');

        return response()->json(['data' => SetupResource::settings($settings, $company, app(CrmAccess::class)->canAtCompany($request->user(), 'crm.settings.manage', $company->id))]);
    }
}
