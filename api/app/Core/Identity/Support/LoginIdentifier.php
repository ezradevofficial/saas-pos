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

    /**
     * Keys for the per-login rate limit: every normalised candidate, so any
     * spelling of an account counts against the same budget.
     *
     * @return non-empty-list<string>
     */
    public static function throttleKeys(string $login): array
    {
        return self::candidates($login) ?: [mb_strtolower(trim($login))];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
