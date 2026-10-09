<?php

namespace App\Core\Branding\Http\Requests;

use App\Core\Branding\Domains\TenantDomains;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public const RESERVED_SLUGS = [
        'www', 'www2', 'api', 'admin', 'app', 'staging', 'dev', 'test', 'edge', 'mail', 'mx', 'ns1', 'ns2',
        'ftp', 'smtp', 'status', 'docs', 'help', 'support', 'billing', 'cdn', 'static', 'assets',
    ];

    /**
     * The reserved names, plus the first label of the platform's own hosts
     * (BRANDING_CNAME_TARGET, APP_URL, FRONTEND_URL), so no tenant can take
     * the subdomain the platform itself answers on.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        $hosts = [
            config('branding.domains.cname_target'),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('app.frontend_url'), PHP_URL_HOST),
        ];
        $labels = array_map(fn ($host) => is_string($host) && $host !== '' ? Str::lower(explode('.', $host)[0]) : null, $hosts);

        return array_values(array_unique([...self::RESERVED_SLUGS, ...array_filter($labels)]));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::lower(trim($this->input('slug')))]);
        }
    }

    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', Rule::notIn(self::reserved())],
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
