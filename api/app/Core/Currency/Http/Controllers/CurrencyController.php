<?php

namespace App\Core\Currency\Http\Controllers;

use App\Core\Currency\Currencies;
use App\Core\Currency\Http\Requests\CurrencyViewRequest;
use Illuminate\Http\JsonResponse;

/** CUR-01: the ISO 4217 catalogue (cached), names in the request language. */
class CurrencyController
{
    public function index(CurrencyViewRequest $request, Currencies $currencies): JsonResponse
    {
        $locale = app()->getLocale();

        return response()->json(['data' => $currencies->all()->values()->map(fn (array $currency) => [
            'code' => $currency['code'],
            'numeric_code' => $currency['numeric_code'],
            'name' => $locale === 'fr' ? $currency['name_fr'] : $currency['name_en'],
            'default_decimals' => $currency['default_decimals'],
            'active_in_iso' => $currency['active_in_iso'],
        ])]);
    }
}
