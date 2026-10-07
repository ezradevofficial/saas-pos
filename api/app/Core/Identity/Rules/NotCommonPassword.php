<?php

namespace App\Core\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * AUTH-02: refuses the 10,000 most common passwords (SecLists, see
 * resources/security/common-passwords.txt), compared lower-cased, and a
 * common password with only digits appended (password123).
 */
class NotCommonPassword implements ValidationRule
{
    public const LIST = 'security/common-passwords.txt';

    /** @var array<string, true>|null */
    private static ?array $passwords = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isCommon($value)) {
            $fail('auth.password.common')->translate();
        }
    }

    public static function isCommon(string $password): bool
    {
        $password = mb_strtolower($password);
        $list = self::passwords();

        if (isset($list[$password])) {
            return true;
        }

        $base = rtrim($password, '0123456789');

        return $base !== $password && mb_strlen($base) >= 4 && isset($list[$base]);
    }

    /** @return array<string, true> */
    private static function passwords(): array
    {
        if (self::$passwords !== null) {
            return self::$passwords;
        }

        $lines = file(resource_path(self::LIST), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_filter($lines, fn (string $line) => ! str_starts_with($line, '#'));

        return self::$passwords = array_fill_keys(array_map(
            fn (string $line) => mb_strtolower(trim($line)),
            $lines,
        ), true);
    }
}
