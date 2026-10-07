<?php

namespace App\Core\Identity\Services;

use App\Core\Identity\Rules\NotCommonPassword;
use App\Core\Tenancy\Models\Tenant;

/** AUTH-02: password rules, with the tenant's minimum length. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /** Upper bound so a bad stored value cannot make every password invalid. */
    public const MAX_MIN_LENGTH = 128;

    /** @return list<mixed> */
    public static function rules(?Tenant $tenant = null): array
    {
        return ['required', 'string', 'min:'.self::minLength($tenant), new NotCommonPassword];
    }

    /** The tenant's minimum, clamped so it is never below 8. */
    public static function minLength(?Tenant $tenant): int
    {
        $value = filter_var($tenant?->setting('password_min_length'), FILTER_VALIDATE_INT);

        if ($value === false) {
            return self::MIN_LENGTH;
        }

        return max(self::MIN_LENGTH, min(self::MAX_MIN_LENGTH, $value));
    }
}
