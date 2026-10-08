<?php

namespace App\Core\Currency\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Http\Requests\CompanyCurrenciesRequest;
use App\Core\Currency\Http\Requests\UpdateCompanyCurrenciesRequest;
use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $this->assertStillActive([$data['base_currency'], ...$reporting]);

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

    /**
     * Validation ran before the transaction: re-check under a share lock,
     * which a concurrent deactivation (FOR UPDATE on the same rows) waits
     * for, so it then sees this company's use of the currency.
     *
     * @param  list<string>  $codes
     */
    private function assertStillActive(array $codes): void
    {
        $active = TenantCurrency::query()->whereIn('code', $codes)->where('active', true)->sharedLock()->pluck('code')->all();
        $missing = array_values(array_diff($codes, $active));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                in_array($codes[0], $missing, true) ? 'base_currency' : 'reporting_currencies' => __('core.currency.not_active'),
            ]);
        }
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
