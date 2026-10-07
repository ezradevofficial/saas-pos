<?php

namespace App\Core\Identity\Support;

/**
 * Phone numbers in E.164 (AUTH-01). Kenyan (+254) and Congolese (+243)
 * mobile numbers have 9 national digits and are also accepted in local
 * form (07..., 08...) or without the plus.
 */
final class PhoneNumber
{
    public const COUNTRY_CODES = ['KE' => '254', 'CD' => '243'];

    public static function normalise(string $input, ?string $country): ?string
    {
        $value = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        if (str_starts_with($value, '+')) {
            return self::valid($value);
        }

        $code = self::COUNTRY_CODES[$country ?? ''] ?? null;

        if ($code === null || ! ctype_digit($value)) {
            return null;
        }

        return match (true) {
            str_starts_with($value, '0') => self::valid('+'.$code.substr($value, 1)),
            str_starts_with($value, $code) => self::valid('+'.$value),
            strlen($value) === 9 => self::valid('+'.$code.$value),
            default => null,
        };
    }

    /**
     * E.164 candidates for a sign-in login without `@`: as given when it has
     * a country code, else Kenyan then Congolese.
     *
     * @return list<string>
     */
    public static function candidates(string $input): array
    {
        $trimmed = trim($input);

        if (str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00')) {
            return array_values(array_filter([self::normalise($trimmed, null)]));
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $country) => self::normalise($trimmed, $country),
            array_keys(self::COUNTRY_CODES),
        ))));
    }

    /** +254712345678 → +254*******78 */
    public static function mask(string $e164): string
    {
        $keep = 4;

        return substr($e164, 0, $keep).str_repeat('*', max(0, strlen($e164) - $keep - 2)).substr($e164, -2);
    }

    private static function valid(string $e164): ?string
    {
        foreach (self::COUNTRY_CODES as $code) {
            if (str_starts_with($e164, '+'.$code)) {
                return preg_match('/^\+'.$code.'[1-9]\d{8}$/', $e164) === 1 ? $e164 : null;
            }
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $e164) === 1 ? $e164 : null;
    }
}
