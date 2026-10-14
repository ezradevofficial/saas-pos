<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\CompanySettingsRequest;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;

/**
 * INV-12, INV-13: a company's costing method (fixed once the company has
 * a movement: `costing_method_locked`) and negative-stock rule; audited
 * as `inventory.settings.*`.
 */
class SettingsController
{
    public function show(CompanySettingsRequest $request, Company $company): JsonResponse
    {
        return $this->present($company, InventorySetting::query()->where('company_id', $company->id)->first());
    }

    public function update(CompanySettingsRequest $request, Company $company): JsonResponse
    {
        $settings = StockInput::transaction(function () use ($request, $company) {
            $settings = InventorySetting::query()->where('company_id', $company->id)->lockForUpdate()->first() ?? new InventorySetting(['company_id' => $company->id]);
            $method = (string) $request->validated('costing_method');

            if ($settings->exists && $settings->costing_method !== $method && Movement::query()->where('company_id', $company->id)->exists()) {
                throw StockLedger::error(422, 'costing_method_locked', field: 'costing_method');
            }

            $settings->fill(['costing_method' => $method, 'allow_negative' => (bool) $request->validated('allow_negative')])->save();

            return $settings->refresh();
        });

        return $this->present($company, $settings);
    }

    private function present(Company $company, ?InventorySetting $settings): JsonResponse
    {
        return new JsonResponse(['data' => [
            'company' => ['id' => $company->id, 'name' => $company->name, 'base_currency' => $company->base_currency],
            'costing_method' => $settings?->costing_method ?? InventorySetting::WEIGHTED_AVERAGE,
            'allow_negative' => $settings?->allow_negative ?? true,
            'costing_locked' => Movement::query()->where('company_id', $company->id)->exists(),
        ]]);
    }
}
