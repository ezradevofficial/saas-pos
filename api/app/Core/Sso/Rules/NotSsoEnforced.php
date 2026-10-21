<?php

namespace App\Core\Sso\Rules;

use App\Core\Sso\SsoEnforcement;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** AUTH-11, ADR 023: no sign-up with an email of a domain whose tenant enforces single sign-on. */
class NotSsoEnforced implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(SsoEnforcement::class)->refusesSignUp($value)) {
            $fail('sso.errors.sign_up_sso_required')->translate();
        }
    }
}
