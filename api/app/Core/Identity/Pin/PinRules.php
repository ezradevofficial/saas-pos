<?php

namespace App\Core\Identity\Pin;

use Illuminate\Validation\ValidationException;

/**
 * AUTH-06: what a POS PIN and a staff card code may be. A PIN is 4 to 6
 * digits and not one an onlooker or a guesser tries first: one digit
 * repeated (0000), a run up or down (1234, 987654), a repeated pair or
 * triple (1212, 123123), a keypad column (2580) or a common choice from the
 * list below. A card code is what the card reader types: 6 to 64 letters
 * or digits, compared without case.
 */
final class PinRules
{
    public const PIN_PATTERN = '/^\d{4,6}$/';

    public const CARD_PATTERN = '/^[A-Za-z0-9]{6,64}$/';

    /** Common PINs that pass the shape checks. */
    private const COMMON = [
        '2580', '0852', '1470', '0741', '3690', '0963', '1004', '2000', '1122', '1313', '6969', '1984', '2001',
        '1998', '1999', '2020', '2021', '2022', '2023', '2024', '2025', '2026', '7777', '5683', '0007', '1990',
        '112233', '121314', '159753', '147258', '258369', '102030', '010203', '696969', '199999', '200000', '111222', '000123',
    ];

    /** @throws ValidationException */
    public static function assertPin(string $pin, string $field = 'pin'): void
    {
        if (preg_match(self::PIN_PATTERN, $pin) !== 1) {
            throw ValidationException::withMessages([$field => __('auth.pin.format')]);
        }

        if (self::isWeak($pin)) {
            throw ValidationException::withMessages([$field => __('auth.pin.weak')]);
        }
    }

    /** @throws ValidationException */
    public static function assertCard(string $card, string $field = 'card'): void
    {
        if (preg_match(self::CARD_PATTERN, $card) !== 1) {
            throw ValidationException::withMessages([$field => __('auth.pin.card_format')]);
        }
    }

    public static function normaliseCard(string $card): string
    {
        return strtoupper($card);
    }

    public static function isWeak(string $pin): bool
    {
        $length = strlen($pin);

        if (count(array_unique(str_split($pin))) === 1 || in_array($pin, self::COMMON, true)) {
            return true;
        }

        $up = true;
        $down = true;

        for ($i = 1; $i < $length; $i++) {
            $step = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && $step === 1;
            $down = $down && $step === -1;
        }

        if ($up || $down) {
            return true;
        }

        // A pattern repeated: 1212, 121212, 123123, 4545.
        foreach ([2, 3] as $period) {
            if ($length % $period === 0 && $length > $period && str_repeat(substr($pin, 0, $period), intdiv($length, $period)) === $pin) {
                return true;
            }
        }

        return false;
    }
}
