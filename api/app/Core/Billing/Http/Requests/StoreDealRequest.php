<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;
use Illuminate\Validation\Validator;

/**
 * ADM-02: draft a custom Enterprise deal for a tenant (sales, finance:
 * `platform.deals.manage`). Lines override catalogue prices of modules
 * or bundles, in minor units a month of the tenant's billing currency;
 * each item once. The deal starts today or later (UTC).
 */
class StoreDealRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.deals.manage';

    /** The calculator's ceiling for a unit price (ADR 013). */
    private const MAX_AMOUNT = 1_000_000_000;

    public function rules(): array
    {
        $amount = ['integer', 'min:0', 'max:'.self::MAX_AMOUNT];

        return [
            'bundle' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\z/'],
            'lines' => ['required', 'array', 'min:1', 'max:40'],
            'lines.*.type' => ['required', 'string', 'in:module,bundle'],
            'lines.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*\z/'],
            'lines.*.amount' => ['required', ...$amount],
            'lines.*.extra_outlet_amount' => ['sometimes', 'nullable', ...$amount],
            'lines.*.minimum_amount' => ['sometimes', 'nullable', ...$amount],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->utc()->toDateString()],
            'effective_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $seen = [];

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $key = ($line['type'] ?? '').':'.($line['key'] ?? '');

                if (isset($seen[$key])) {
                    $validator->errors()->add("lines.{$i}.key", __('billing.deals.duplicate_line'));
                }

                $seen[$key] = true;
            }
        });
    }
}
