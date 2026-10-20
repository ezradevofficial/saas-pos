<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Billing\Catalogue\Http\Requests\QuoteCatalogueRequest;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use App\Core\Identity\Rules\LoginAvailable;
use App\Core\Identity\Support\PhoneNumber;
use App\Core\Onboarding\BusinessTypes;
use App\Core\Platform\Http\Requests\PlatformRequest;
use App\Core\Tenancy\TenantProvisioner;
use Illuminate\Validation\Rule;

/**
 * ONB-08: a tenant created by platform staff for a customer (owner and
 * support staff): the business, its country and language, the business
 * type and modules, and the customer's contact, who becomes its Owner.
 */
class CreateAssistedTenantRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.tenants.create';

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');

        $this->merge([
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            'phone' => is_string($phone) && trim($phone) !== ''
                ? (PhoneNumber::normalise($phone, is_string($this->input('country')) ? $this->input('country') : null) ?? $phone)
                : null,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'business_name' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', Rule::in(array_keys(TenantProvisioner::COUNTRIES))],
            'locale' => ['required', 'string', Rule::in(['en', 'fr'])],
            'business_type' => ['required', 'string', Rule::in(app(BusinessTypes::class)->keys())],
            'outlets' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'modules' => ['present', 'array', 'max:40'],
            'modules.*' => ['required', 'string', 'max:40', 'distinct', Rule::exists('catalogue_modules', 'key')->whereNull('archived_at')],
            'quantities' => ['sometimes', 'array:'.implode(',', QuoteCatalogueRequest::UNITS)],
            'cycle' => ['required', 'string', Rule::in(QuoteRequest::CYCLES)],
            'bundle' => ['nullable', 'string', 'max:40', Rule::exists('catalogue_bundles', 'key')->whereNull('archived_at')],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'prohibits:phone', 'string', 'email', 'max:255', new LoginAvailable],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:/^\+[1-9]\d{7,14}$/', new LoginAvailable],
        ];

        foreach (QuoteCatalogueRequest::UNITS as $unit) {
            $rules["quantities.{$unit}"] = ['sometimes', 'integer', $unit === 'device' ? 'min:0' : 'min:1', 'max:100000'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'business_name' => __('onboarding.attributes.business_name'),
            'business_type' => __('onboarding.attributes.business_type'),
            'contact_name' => __('onboarding.attributes.contact_name'),
            'modules' => __('onboarding.attributes.modules'),
        ];
    }
}
