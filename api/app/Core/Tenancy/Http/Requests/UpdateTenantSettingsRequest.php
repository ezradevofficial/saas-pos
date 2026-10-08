<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Identity\Services\SessionTimeout;
use Illuminate\Validation\Rule;

/**
 * AUTH-02: password minimum 8..64. AUTH-09: idle timeout 15..480 minutes.
 * L10N-01: the tenant's default language.
 */
class UpdateTenantSettingsRequest extends TenantSettingsRequest
{
    public const PASSWORD_MIN = 8;

    public const PASSWORD_MAX = 64;

    public function rules(): array
    {
        return [
            'password_min_length' => ['sometimes', 'required', 'integer', 'between:'.self::PASSWORD_MIN.','.self::PASSWORD_MAX],
            'session_timeout_minutes' => ['sometimes', 'required', 'integer', 'between:'.SessionTimeout::MIN.','.SessionTimeout::MAX],
            'default_locale' => ['sometimes', 'required', 'string', Rule::in(['en', 'fr'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'password_min_length' => __('core.settings.attributes.password_min_length'),
            'session_timeout_minutes' => __('core.settings.attributes.session_timeout_minutes'),
            'default_locale' => __('core.settings.attributes.default_locale'),
        ];
    }
}
