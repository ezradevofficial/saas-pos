<?php

namespace App\Core\Identity\Support;

/** A sign-in login: an email address or a phone number (AUTH-01). */
final class LoginIdentifier
{
    /**
     * Normalised forms to look up, most likely first.
     *
     * @return list<string>
     */
    public static function candidates(string $login): array
    {
        $login = trim($login);

        if ($login === '') {
            return [];
        }

        if (str_contains($login, '@')) {
            return [mb_strtolower($login)];
        }

        return PhoneNumber::candidates($login);
    }

    /** One key per account whatever the spelling: used by the per-login rate limit. */
    public static function throttleKey(string $login): string
    {
        return self::candidates($login)[0] ?? mb_strtolower(trim($login));
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
