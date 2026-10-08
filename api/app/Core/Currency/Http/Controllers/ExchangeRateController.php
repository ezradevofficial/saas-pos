<?php

namespace App\Core\Currency\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Http\Requests\CurrentExchangeRateRequest;
use App\Core\Currency\Http\Requests\ListExchangeRatesRequest;
use App\Core\Currency\Http\Requests\StoreExchangeRateRequest;
use App\Core\Currency\Http\Resources\ExchangeRateResource;
use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\RateAlert;
use App\Core\Currency\Rate;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CUR-03: a company's rate history and the rates in force; CUR-07: shop
 * rates outside the company's tolerance are saved with an alert.
 */
class ExchangeRateController
{
    /** numeric(9,4): larger moves are stored at this ceiling. */
    private const MAX_CHANGE_PERCENT = '99999.9999';

    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly Auditor $auditor,
    ) {}

    public function index(ListExchangeRatesRequest $request, Company $company): AnonymousResourceCollection
    {
        $query = ExchangeRate::query()->where('company_id', $company->id);

        if ($request->filled('pair')) {
            [$base, $quote] = explode('/', $request->validated('pair'));
            $query->where('base', $base)->where('quote', $quote);
        }

        if ($request->filled('from')) {
            $query->where('effective_at', '>=', CarbonImmutable::parse($request->validated('from'), $company->timezone)->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('effective_at', '<', CarbonImmutable::parse($request->validated('to'), $company->timezone)->addDay()->startOfDay()->utc());
        }

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        return ExchangeRateResource::collection(
            $query->orderByDesc('effective_at')->orderByDesc('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function current(CurrentExchangeRateRequest $request, Company $company): JsonResponse
    {
        if ($request->filled('pair')) {
            [$base, $quote] = explode('/', $request->validated('pair'));

            return response()->json(['data' => $this->rates->current($company, $base, $quote)->toArray()]);
        }

        return response()->json(['data' => array_map(fn (Rate $rate) => $rate->toArray(), $this->rates->all($company))]);
    }

    public function store(StoreExchangeRateRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $effectiveAt = isset($data['effective_at']) ? CarbonImmutable::parse($data['effective_at'])->utc() : CarbonImmutable::now();

        [$rate, $alert] = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $company, $effectiveAt, $request) {
            $previous = $this->rates->previous($company, $data['base'], $data['quote'], $effectiveAt);

            try {
                $rate = DB::connection(TenantContext::CONNECTION)->transaction(fn () => ExchangeRate::create([
                    'company_id' => $company->id,
                    'base' => $data['base'],
                    'quote' => $data['quote'],
                    'kind' => 'shop',
                    'mid' => Rate::normalise($data['mid']),
                    'buy' => isset($data['buy']) ? Rate::normalise($data['buy']) : null,
                    'sell' => isset($data['sell']) ? Rate::normalise($data['sell']) : null,
                    'effective_at' => $effectiveAt,
                    'source' => 'manual',
                    'entered_by' => $request->user()->id,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['effective_at' => __('core.exchange_rate.duplicate')]);
            }

            return [$rate, $previous === null ? null : $this->checkTolerance($company, $rate, $previous)];
        });

        $response = ExchangeRateResource::make($rate);

        if ($alert !== null) {
            $response->additional(['meta' => ['warning' => [
                'code' => 'rate_tolerance_exceeded',
                'message' => __('core.exchange_rate.tolerance_exceeded', [
                    'pair' => $alert->pair,
                    'change' => $alert->change_percent,
                    'tolerance' => $company->rate_tolerance_percent,
                ]),
                'previous_mid' => $alert->previous_mid,
                'change_percent' => $alert->change_percent,
                'tolerance_percent' => (string) $company->rate_tolerance_percent,
            ]]]);
        }

        return $response->response()->setStatusCode(201);
    }

    /**
     * CUR-07: compare the new mid with the previous rate of the pair (shop
     * or reference). Beyond the tolerance: an alert row and an audit entry.
     */
    private function checkTolerance(Company $company, ExchangeRate $rate, Rate $previous): ?RateAlert
    {
        $old = BigDecimal::of($previous->mid);
        $change = BigDecimal::of($rate->mid)->minus($old)->abs()
            ->multipliedBy(100)
            ->dividedBy($old, 4, RoundingMode::HalfUp);

        if (! $change->isGreaterThan((string) $company->rate_tolerance_percent)) {
            return null;
        }

        $alert = RateAlert::create([
            'company_id' => $company->id,
            'exchange_rate_id' => $rate->id,
            'pair' => "{$rate->base}/{$rate->quote}",
            'previous_mid' => $previous->mid,
            'new_mid' => $rate->mid,
            'change_percent' => (string) BigDecimal::min($change, self::MAX_CHANGE_PERCENT),
            'entered_by' => $rate->entered_by,
        ]);

        $this->auditor->record('core.exchange_rate.alert', $rate, ['mid' => $previous->mid], [
            'mid' => $rate->mid,
            'pair' => $alert->pair,
            'change_percent' => $alert->change_percent,
            'tolerance_percent' => (string) $company->rate_tolerance_percent,
        ]);

        return $alert;
    }
}
