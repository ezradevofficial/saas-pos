<?php

namespace App\Core\Branding;

use InvalidArgumentException;

/**
 * BR-03: colour maths, a line-by-line port of packages/tokens
 * (contrast.js and derive.js) so the server checks exactly what the
 * editor shows. Both are tested against
 * packages/tokens/test/fixtures/brand-vectors.json.
 *
 * - contrastRatio: WCAG 2.x ratio between two hex colours (1 to 21);
 * - hexToOklch / oklchToHex: OKLCH conversions (out-of-gamut chroma is
 *   reduced until the colour fits sRGB);
 * - brandPair: hover, tint and on-colour derived from a brand colour.
 */
final class Colour
{
    public const ON_LIGHT = '#ffffff';

    public const ON_DARK = '#18181b';

    /** @return array{0: int, 1: int, 2: int} */
    public static function parseHex(string $hex): array
    {
        $h = ltrim(trim($hex), '#');

        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) !== 1) {
            throw new InvalidArgumentException("Invalid hex colour: {$hex}");
        }

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /** Lower-case six-digit hex (#abc becomes #aabbcc). */
    public static function normalise(string $hex): string
    {
        return '#'.implode('', array_map(fn (int $v) => str_pad(dechex($v), 2, '0', STR_PAD_LEFT), self::parseHex($hex)));
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $v) {
            $c = $v / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::parseHex($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{l: float, c: float, h: float} */
    public static function hexToOklch(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $v) => self::toLinear($v), self::parseHex($hex));
        $l = self::cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = self::cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = self::cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);
        $L = 0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.808675766 * $s;
        $c = hypot($A, $B);
        $h = $c < 1e-7 ? 0.0 : fmod((atan2($B, $A) * 180) / M_PI + 360, 360);

        return ['l' => $L, 'c' => $c, 'h' => $h];
    }

    /** @param array{l: float, c: float, h: float} $color */
    public static function oklchToHex(array $color): string
    {
        $c = $color['c'];
        $rgb = self::oklchToLinear($color['l'], $c, $color['h']);

        if (! self::inGamut($rgb)) {
            $lo = 0.0;
            $hi = $c;

            for ($i = 0; $i < 24; $i++) {
                $mid = ($lo + $hi) / 2;

                if (self::inGamut(self::oklchToLinear($color['l'], $mid, $color['h']))) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }

            $c = $lo;
            $rgb = self::oklchToLinear($color['l'], $c, $color['h']);
        }

        return '#'.implode('', array_map(function (float $v) {
            $channel = self::jsRound(min(1, max(0, self::fromLinear(min(1, max(0, $v))))) * 255);

            return str_pad(dechex((int) $channel), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    /**
     * Hover, tint and on-colour of a brand colour: hover 12% darker (light)
     * or lighter (dark) in OKLCH lightness; tint at lightness 0.96 (light)
     * or 0.22 (dark) with muted chroma; on-colour white or near-black,
     * whichever contrasts more.
     *
     * @return array{base: string, hover: string, tint: string, on: string}
     */
    public static function brandPair(string $hex, string $mode = 'light'): array
    {
        $base = self::hexToOklch($hex);
        $dark = $mode === 'dark';
        $hover = [...$base, 'l' => $dark ? min(1, $base['l'] * 1.12) : $base['l'] * 0.88];
        $tint = ['l' => $dark ? 0.22 : 0.96, 'c' => min($base['c'] * 0.25, 0.04), 'h' => $base['h']];
        $on = self::contrastRatio(self::ON_LIGHT, $hex) >= self::contrastRatio(self::ON_DARK, $hex) ? self::ON_LIGHT : self::ON_DARK;

        return [
            'base' => self::normalise($hex),
            'hover' => self::oklchToHex($hover),
            'tint' => self::oklchToHex($tint),
            'on' => $on,
        ];
    }

    private static function toLinear(int $v): float
    {
        $c = $v / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function fromLinear(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function oklchToLinear(float $l, float $c, float $h): array
    {
        $a = $c * cos(($h * M_PI) / 180);
        $b = $c * sin(($h * M_PI) / 180);
        $l_ = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m_ = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s_ = ($l - 0.0894841775 * $a - 1.291485548 * $b) ** 3;

        return [
            4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_,
            -1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_,
            -0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.707614701 * $s_,
        ];
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $v) {
            if ($v < -1e-6 || $v > 1 + 1e-6) {
                return false;
            }
        }

        return true;
    }

    /** Math.cbrt: the real cube root, negative numbers included. */
    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    /** Math.round: halves go up (towards +infinity). */
    private static function jsRound(float $x): float
    {
        return floor($x + 0.5);
    }
}
