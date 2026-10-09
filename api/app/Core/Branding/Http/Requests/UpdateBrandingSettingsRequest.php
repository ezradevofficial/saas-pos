<?php

namespace App\Core\Branding\Http\Requests;

use App\Core\Branding\Domains\TenantDomains;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * BR-04, BR-06: the subdomain, the email sender and the SMS sender ID.
 *
 * - slug: 1 to 63 lower-case letters, digits and hyphens, not a reserved name;
 * - email_from_address: on one of the tenant's verified domains;
 * - email_from_name: one line, at most 100 characters;
 * - sms_sender_id: 3 to 11 letters, digits or spaces with at least one letter
 *   (the alphanumeric sender IDs networks accept).
 */
class UpdateBrandingSettingsRequest extends DomainRequest
{
    public const RESERVED_SLUGS = ['www', 'api', 'app', 'admin', 'mail', 'smtp', 'status', 'help', 'support', 'docs', 'static', 'assets', 'cdn', 'auth', 'login', 'billing'];

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::lower(trim($this->input('slug')))]);
        }
    }

    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', 'not_in:'.implode(',', self::RESERVED_SLUGS)],
            'email_from_name' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[^\r\n<>"]*$/'],
            'email_from_address' => ['sometimes', 'nullable', 'string', 'max:254', 'email:rfc'],
            'sms_sender_id' => ['sometimes', 'nullable', 'string', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9 ]{3,11}$/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $address = $this->input('email_from_address');

            if ($validator->errors()->isNotEmpty() || ! is_string($address) || $address === '') {
                return;
            }

            if (app(TenantDomains::class)->verified(Str::after($address, '@')) === null) {
                $validator->errors()->add('email_from_address', __('branding.errors.sender_domain'));
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'slug' => __('branding.attributes.slug'),
            'email_from_name' => __('branding.attributes.email_from_name'),
            'email_from_address' => __('branding.attributes.email_from_address'),
            'sms_sender_id' => __('branding.attributes.sms_sender_id'),
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => __('branding.errors.slug_format'),
            'slug.not_in' => __('branding.errors.slug_reserved'),
            'email_from_name.regex' => __('branding.errors.from_name_format'),
            'sms_sender_id.regex' => __('branding.errors.sms_sender_format'),
        ];
    }
}
