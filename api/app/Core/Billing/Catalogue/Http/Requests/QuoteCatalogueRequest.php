<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** ONB-03: price a selection (no sign-in; rate-limited). */
class QuoteCatalogueRequest extends FormRequest
{
    public const UNITS = ['outlet', 'device', 'warehouse', 'online_store', 'company', 'employee'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'country' => ['required', 'string', 'size:2', Rule::exists('catalogue_countries', 'country')],
            'cycle' => ['required', 'string', Rule::in(QuoteRequest::CYCLES)],
            'modules' => ['present', 'array', 'max:40'],
            'modules.*' => ['required', 'string', 'max:40', 'distinct'],
            'quantities' => ['sometimes', 'array:'.implode(',', self::UNITS)],
            'bundle' => ['nullable', 'string', 'max:40'],
            'coupon' => ['nullable', 'string', 'max:40'],
        ];

        foreach (self::UNITS as $unit) {
            $rules["quantities.{$unit}"] = ['sometimes', 'integer', $unit === 'device' ? 'min:0' : 'min:1', 'max:100000'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('modules') === [] && blank($this->input('bundle'))) {
                $validator->errors()->add('modules', __('billing.quote.nothing_selected'));
            }
        }];
    }
}
