<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Billing\Catalogue\Http\Requests\QuoteCatalogueRequest;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use Illuminate\Validation\Rule;

/**
 * ONB-03, ONB-04: the modules, quantities, cycle and optional bundle the
 * trial starts with. The country is the tenant's, never input; the
 * catalogue prices the selection (422 when it cannot be sold).
 */
class ChooseModulesRequest extends EditOnboardingRequest
{
    protected string $permission = 'core.onboarding.modules';

    public function rules(): array
    {
        $rules = [
            'modules' => ['present', 'array', 'max:40'],
            'modules.*' => ['required', 'string', 'max:40', 'distinct', Rule::exists('catalogue_modules', 'key')->whereNull('archived_at')],
            'quantities' => ['sometimes', 'array:'.implode(',', QuoteCatalogueRequest::UNITS)],
            'cycle' => ['required', 'string', Rule::in(QuoteRequest::CYCLES)],
            'bundle' => ['nullable', 'string', 'max:40', Rule::exists('catalogue_bundles', 'key')->whereNull('archived_at')],
        ];

        foreach (QuoteCatalogueRequest::UNITS as $unit) {
            $rules["quantities.{$unit}"] = ['sometimes', 'integer', $unit === 'device' ? 'min:0' : 'min:1', 'max:100000'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'modules' => __('onboarding.attributes.modules'),
            'cycle' => __('onboarding.attributes.cycle'),
            'bundle' => __('onboarding.attributes.bundle'),
        ];
    }
}
