<?php

namespace App\Core\Branding\Http\Requests;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * BR-05: add a custom domain: a full host name (`erp.company.co.ke`),
 * lower-cased, never an address, `localhost`, or the platform's own
 * domain or one of its subdomains.
 */
class StoreDomainRequest extends DomainRequest
{
    public const HOST_PATTERN = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('host'))) {
            $this->merge(['host' => rtrim(Str::lower(trim($this->input('host'))), '.')]);
        }
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', 'regex:'.self::HOST_PATTERN],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $host = (string) $this->input('host');
            $base = Str::lower((string) config('branding.base_domain'));

            if ($base !== '' && ($host === $base || str_ends_with($host, '.'.$base))) {
                $validator->errors()->add('host', __('branding.errors.platform_domain'));
            }
        }];
    }

    public function attributes(): array
    {
        return ['host' => __('branding.attributes.host')];
    }

    public function messages(): array
    {
        return ['host.regex' => __('branding.errors.host_format')];
    }
}
