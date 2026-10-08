<?php

namespace App\Core\Currency\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Http\Requests\CompanyCurrenciesRequest;
use App\Core\Currency\Http\Requests\UpdateCompanyCurrenciesRequest;
use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * CUR-02: a company's base currency (locked after the first posting) and
 * its ordered reporting currencies (at most three). A change is audited on
 * the company as `core.company.currencies_update`, before and after.
 */
class CompanyCurrencyController
{
    public function __construct(
        private readonly BaseCurrencyLock $lock,
        private readonly Auditor $auditor,
    ) {}

    public function show(CompanyCurrenciesRequest $request, Company $company): JsonResponse
    {
        return $this->respond($company);
    }

    public function update(UpdateCompanyCurrenciesRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $reporting = array_values($data['reporting_currencies']);

        if (count($reporting) > CompanyCurrency::MAX_REPORTING) {
            throw new ApiException(422, 'too_many_reporting_currencies', __('core.currency.too_many_reporting_currencies', ['max' => CompanyCurrency::MAX_REPORTING]));
        }

        $company = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data, $reporting) {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->lock->assertCanChange($company, $data['base_currency']);

            $before = $this->values($company);

            $company->base_currency = $data['base_currency'];
            $company->save();

            if ($before['reporting_currencies'] !== $reporting) {
                CompanyCurrency::query()->where('company_id', $company->id)->delete();

                foreach ($reporting as $index => $code) {
                    CompanyCurrency::create(['company_id' => $company->id, 'code' => $code, 'position' => $index + 1]);
                }
            }

            $after = $this->values($company);

            if ($after !== $before) {
                $this->auditor->record('core.company.currencies_update', $company, $before, $after);
            }

            return $company;
        });

        return $this->respond($company);
    }

    /** @return array{base_currency: string, reporting_currencies: list<string>} */
    private function values(Company $company): array
    {
        return [
            'base_currency' => $company->base_currency,
            'reporting_currencies' => CompanyCurrency::query()
                ->where('company_id', $company->id)
                ->orderBy('position')
                ->pluck('code')
                ->all(),
        ];
    }

    private function respond(Company $company): JsonResponse
    {
        return response()->json(['data' => [
            ...$this->values($company),
            'base_currency_locked' => $this->lock->isLocked($company),
            'base_currency_locked_at' => $company->base_currency_locked_at?->toIso8601String(),
        ]]);
    }
}
