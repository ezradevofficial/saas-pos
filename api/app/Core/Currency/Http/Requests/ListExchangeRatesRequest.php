<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Currency\Http\Lists\ExchangeRateList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;

/**
 * CUR-03: the rate history, newest first: `?pair=USD/CDF` (stored either
 * way; each row's `direction` says which),
 * `?from=` and `?to=` (dates, in the company's time zone; `to` may also be
 * an ISO 8601 instant with its offset, e.g. now, to leave out rates that
 * take effect later the same day), `?kind=` reference or shop,
 * `?per_page` (50, at most 200), `?sort` (newest first by default) and
 * an export (`?format`, `?columns[]`; ExchangeRateList, EXP-01). No text
 * search: `?pair` and `?kind` are the list's filters.
 */
class ListExchangeRatesRequest extends ExchangeRateRequest
{
    use SortsAndExports;

    private ?ExchangeRateList $definition = null;

    public function list(): ListDefinition
    {
        return $this->definition ??= new ExchangeRateList($this->route('company'));
    }

    public const PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    /** A date, or an instant with seconds (optionally fractions) and an offset or Z. */
    public const TO_PATTERN = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+\-]\d{2}:\d{2}))?\z/';

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'pair' => ['sometimes', 'string', 'regex:/^[A-Z]{3}\/[A-Z]{3}\z/'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'string', 'regex:'.self::TO_PATTERN, 'date', 'after_or_equal:from'],
            'kind' => ['sometimes', 'string', 'in:reference,shop'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    /** `to` is an instant (not a whole day). */
    public function toIsInstant(): bool
    {
        return strlen((string) $this->validated('to')) > 10;
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::PER_PAGE);
    }
}
