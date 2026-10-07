<?php

namespace App\Core\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Email or phone not used by any user in any tenant. A plain `unique` rule
 * would only see the current tenant's rows (RLS), so this asks the
 * security-definer lookup instead.
 */
class LoginAvailable implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (DB::selectOne('select auth_tenant_for_login(?) as tenant_id', [$value])?->tenant_id !== null) {
            $fail('validation.unique')->translate();
        }
    }
}
