<?php

namespace Modules\POS\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET pos/insights (TEN-07): consolidated sales for a period, for a holder
 * of `pos.sale.view` at the locations they reach (RBAC-04). `from` and `to`
 * are calendar days, read in each company's time zone; at most 366 days.
 * `company`, `branch` and `location` narrow it; `currency` is the
 * reporting currency (an active currency of the tenant).
 */
class SalesInsightsRequest extends FormRequest
{
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'pos.sale.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail) {
                $from = $this->input('from');

                if (is_string($from) && is_string($value) && strtotime($value) !== false && strtotime($from) !== false
                    && (strtotime($value) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
                    $fail(__('pos.insights.period_too_long', ['days' => self::MAX_DAYS]));
                }
            }],
            // Under RLS: another tenant's id does not exist.
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'branch' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
            'location' => ['sometimes', 'uuid', Rule::exists('locations', 'id')],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
        ];
    }
}
