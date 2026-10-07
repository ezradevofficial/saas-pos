<?php

namespace App\Core\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AUTH-02: bcrypt reads only the first 72 bytes of a password, so longer
 * ones are refused rather than silently truncated.
 */
class MaxBytes implements ValidationRule
{
    public function __construct(public readonly int $max = 72) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->max) {
            $fail('auth.password.too_long')->translate(['max' => $this->max]);
        }
    }
}
