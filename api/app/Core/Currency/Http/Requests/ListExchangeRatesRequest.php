<?php

namespace App\Core\Currency\Http\Requests;

/**
 * CUR-03: the rate history, newest first: `?pair=USD/CDF` (stored either
 * way; each row's `direction` says which),
 * `?from=` and `?to=` (dates, in the company's time zone), `?kind=`
 * reference or shop, `?per_page` (50, at most 200).
 */
class ListExchangeRatesRequest extends ExchangeRateRequest
{
    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public function rules(): array
    {
        return [
            'pair' => ['sometimes', 'string', 'regex:/^[A-Z]{3}\/[A-Z]{3}\z/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['sometimes', 'string', 'in:reference,shop'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::PER_PAGE);
    }
}
