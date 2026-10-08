<?php

namespace App\Core\Currency\Http\Controllers;

use App\Core\Currency\Currencies;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\CurrencyUsage;
use App\Core\Currency\Http\Requests\CurrencyViewRequest;
use App\Core\Currency\Http\Requests\StoreTenantCurrencyRequest;
use App\Core\Currency\Http\Requests\UpdateTenantCurrencyRequest;
use App\Core\Currency\Http\Resources\TenantCurrencyResource;
use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CUR-01: the currencies the tenant uses. Row-level security limits every
 * query to the current tenant; changes are audited (`core.currency.*`).
 */
class TenantCurrencyController
{
    public function __construct(
        private readonly Currencies $catalogue,
        private readonly CurrencyUsage $usage,
    ) {}

    public function index(CurrencyViewRequest $request): AnonymousResourceCollection
    {
        return TenantCurrencyResource::collection(TenantCurrency::query()->orderBy('code')->get());
    }

    public function store(StoreTenantCurrencyRequest $request): TenantCurrencyResource
    {
        $data = $request->validated();

        try {
            $currency = TenantCurrency::create([
                'code' => $data['code'],
                'decimals' => $data['decimals'] ?? $this->catalogue->find($data['code'])['default_decimals'] ?? CurrencyDecimals::FALLBACK,
                'cash_rounding_minor' => $data['cash_rounding_minor'] ?? 1,
                'active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another activation of the same code.
            throw ValidationException::withMessages(['code' => __('validation.unique', ['attribute' => __('core.currency.attributes.code')])]);
        }

        return TenantCurrencyResource::make($currency);
    }

    public function update(UpdateTenantCurrencyRequest $request, TenantCurrency $tenantCurrency): TenantCurrencyResource
    {
        $data = $request->validated();

        $currency = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($tenantCurrency, $data) {
            $currency = TenantCurrency::query()->whereKey($tenantCurrency->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('decimals', $data) && (int) $data['decimals'] !== $currency->decimals && $this->usage->isUsed($currency->code)) {
                throw new ApiException(422, 'currency_decimals_locked', __('core.currency.decimals_locked'));
            }

            // `boolean` also accepts 0 and "0".
            $deactivating = array_key_exists('active', $data) && ! (bool) $data['active'];

            if ($deactivating && $currency->active && $this->usedByACompany($currency->code)) {
                throw new ApiException(422, 'currency_in_use', __('core.currency.in_use'));
            }

            $currency->fill($data)->save();

            return $currency;
        });

        return TenantCurrencyResource::make($currency);
    }

    /** A company of the tenant (archived ones included) bases or reports in $code. */
    private function usedByACompany(string $code): bool
    {
        return Company::query()->where('base_currency', $code)->exists()
            || CompanyCurrency::query()->where('code', $code)->exists();
    }
}
