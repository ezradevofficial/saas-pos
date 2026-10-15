<?php

namespace Modules\FixedAssets\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Http\Requests\ReportRequest;
use Modules\FixedAssets\Http\Resources\Values;
use Modules\FixedAssets\Reports\Reports;

/** FA-26: a company's depreciation, additions and disposals, and net book value by category; money for value holders only (RBAC-05). */
class ReportController
{
    private const MONEY = ['amount', 'cost', 'accumulated', 'impairment', 'book_value', 'proceeds', 'gain'];

    public function __construct(private readonly Reports $reports) {}

    public function depreciation(ReportRequest $request): JsonResponse
    {
        [$company, $from, $to] = $this->period($request);

        return $this->answer($request, $this->reports->depreciation($company, $from, $to, (string) $request->validated('group', 'category')), $from, $to);
    }

    public function movements(ReportRequest $request): JsonResponse
    {
        [$company, $from, $to] = $this->period($request);
        $data = $this->reports->movements($company, $from, $to);

        return $this->answer($request, ['additions' => $data['additions'], 'disposals' => $data['disposals']], $from, $to);
    }

    public function netBookValue(ReportRequest $request): JsonResponse
    {
        return $this->answer($request, $this->reports->netBookValue($request->company()), null, null);
    }

    /** @return array{0: \App\Core\Tenancy\Models\Company, 1: string, 2: string} */
    private function period(ReportRequest $request): array
    {
        $company = $request->company();
        $today = CarbonImmutable::now($company->timezone ?: 'UTC');

        return [$company, (string) $request->validated('from', $today->startOfYear()->toDateString()), (string) $request->validated('to', $today->toDateString())];
    }

    private function answer(ReportRequest $request, array $data, ?string $from, ?string $to): JsonResponse
    {
        $company = $request->company();
        $values = Values::atCompany($request, $company->id);
        $currency = (string) $company->base_currency;
        $shape = function ($value, $key = null) use (&$shape, $values, $currency) {
            if (is_array($value)) {
                $out = [];

                foreach ($value as $k => $v) {
                    $out[$k] = $shape($v, $k);
                }

                return $out;
            }

            return is_string($key) && in_array($key, self::MONEY, true) && is_int($value) ? Values::money($values, $value, $currency) : $value;
        };

        return response()->json(['data' => $shape($data), 'currency' => $currency, 'from' => $from, 'to' => $to]);
    }
}
