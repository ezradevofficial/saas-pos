<?php

namespace App\Core\Sso\Http\Requests;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * AUTH-11: claim an email domain (`company.co.ke`), lower-cased, never an
 * address, `localhost`, or the platform's own domain or its subdomains.
 */
class StoreSsoDomainRequest extends SsoManageRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('domain'))) {
            $this->merge(['domain' => rtrim(Str::lower(trim(Str::afterLast($this->input('domain'), '@'))), '.')]);
        }
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:'.SaveSsoConnectionRequest::DOMAIN_PATTERN],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $domain = (string) $this->input('domain');
            $base = Str::lower((string) config('branding.base_domain'));

            if ($base !== '' && ($domain === $base || str_ends_with($domain, '.'.$base))) {
                $validator->errors()->add('domain', __('sso.errors.platform_domain'));
            }
        }];
    }

    public function attributes(): array
    {
        return ['domain' => __('sso.attributes.domain')];
    }

    public function messages(): array
    {
        return ['domain.regex' => __('sso.errors.domain_format')];
    }
}
