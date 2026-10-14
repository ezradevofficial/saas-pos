<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\Procurement\Http\Requests\SettingsRequest;
use Modules\Procurement\Http\Resources\Refs;
use Modules\Procurement\Models\Settings;
use Modules\Procurement\Support\Tx;

/** PRC-01: a company's three-way match tolerances and over-receipt allowance (audited). */
class SettingsController
{
    public function show(SettingsRequest $request, Company $company): JsonResponse
    {
        return $this->respond($company, Settings::query()->where('company_id', $company->id)->first());
    }

    public function update(SettingsRequest $request, Company $company): JsonResponse
    {
        $settings = Tx::run(function () use ($request, $company) {
            $settings = Settings::query()->where('company_id', $company->id)->lockForUpdate()->first() ?? new Settings(['company_id' => $company->id]);
            $settings->fill($request->validated())->save();

            return $settings;
        });

        return $this->respond($company, $settings->refresh());
    }

    private function respond(Company $company, ?Settings $settings): JsonResponse
    {
        return response()->json(['data' => [
            'company' => Refs::named($company),
            'price_tolerance_percent' => Refs::qty($settings?->price_tolerance_percent ?? '0'),
            'qty_tolerance_percent' => Refs::qty($settings?->qty_tolerance_percent ?? '0'),
            'over_receipt_percent' => Refs::qty($settings?->over_receipt_percent ?? '0'),
        ]]);
    }
}
