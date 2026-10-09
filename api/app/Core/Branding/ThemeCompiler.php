<?php

namespace App\Core\Branding;

use RuntimeException;

/**
 * BR-02, BR-03: a stored tenant theme (preset plus a few choices) as token
 * overrides per mode, and the WCAG AA checks that decide whether it may be
 * published. A port of packages/tokens/src/brand.js, tested against the
 * same vectors (packages/tokens/test/fixtures/brand-vectors.json).
 *
 * The stored theme holds base colours only: hover, tint and on-colours are
 * always derived here, never typed. Only OVERRIDABLE tokens are ever set;
 * status colours, spacing, type sizes and the focus ring never are.
 */
final class ThemeCompiler
{
    public const PRESETS = ['light', 'dark', 'executive', 'warm'];

    public const MODES = ['light', 'dark'];

    /** The tokens a tenant theme may set (packages/tokens OVERRIDABLE_TOKENS). */
    public const OVERRIDABLE = [
        'primary', 'primary-hover', 'on-primary', 'primary-tint',
        'accent', 'accent-hover', 'on-accent',
        'sidebar', 'sidebar-ink', 'sidebar-active', 'sidebar-ink-active', 'sidebar-border',
        'radius-md', 'radius-lg',
        'font-sans', 'font-display',
    ];

    public const CORNERS = [
        'sharp' => ['radius-md' => '2px', 'radius-lg' => '4px'],
        'standard' => ['radius-md' => '6px', 'radius-lg' => '10px'],
        'soft' => ['radius-md' => '10px', 'radius-lg' => '16px'],
    ];

    public const FONTS = [
        'geist' => ['sans' => '"Geist Variable", "Geist", "Segoe UI", system-ui, sans-serif', 'display' => '"Geist Variable", "Geist", "Segoe UI", system-ui, sans-serif'],
        'ibm_plex_sans' => ['sans' => '"IBM Plex Sans Variable", "IBM Plex Sans", "Segoe UI", system-ui, sans-serif', 'display' => '"IBM Plex Sans Variable", "IBM Plex Sans", "Segoe UI", system-ui, sans-serif'],
        'newsreader_geist' => ['sans' => '"Geist Variable", "Geist", "Segoe UI", system-ui, sans-serif', 'display' => '"Newsreader Variable", "Newsreader", Georgia, "Times New Roman", serif'],
    ];

    /** Sidebar light or dark: the preset whose sidebar-* set applies in light mode. */
    public const SIDEBARS = ['light' => 'light', 'dark' => 'executive'];

    private const SIDEBAR_TOKENS = ['sidebar', 'sidebar-ink', 'sidebar-active', 'sidebar-ink-active', 'sidebar-border'];

    public const TEXT = 4.5;

    public const NON_TEXT = 3.0;

    /** [pair, foreground, background, required ratio], checked in both modes. */
    public const PAIRS = [
        ['primary_text_page', 'primary', 'surface-100', self::TEXT],
        ['primary_text_card', 'primary', 'surface-200', self::TEXT],
        ['on_primary', 'on-primary', 'primary', self::TEXT],
        ['primary_tint', 'ink', 'primary-tint', self::TEXT],
        ['on_accent', 'on-accent', 'accent', self::TEXT],
        ['accent_fill', 'accent', 'surface-200', self::NON_TEXT],
        ['sidebar_text', 'sidebar-ink', 'sidebar', self::TEXT],
        ['sidebar_active', 'sidebar-ink-active', 'sidebar-active', self::TEXT],
    ];

    public const PAIR_FIELD = [
        'primary_text_page' => 'colors.primary',
        'primary_text_card' => 'colors.primary',
        'on_primary' => 'colors.primary',
        'primary_tint' => 'colors.primary',
        'on_accent' => 'colors.accent',
        'accent_fill' => 'colors.accent',
        'sidebar_text' => 'sidebar',
        'sidebar_active' => 'sidebar',
    ];

    /** @var array<string, array<string, string>>|null */
    private static ?array $presets = null;

    /** @return array<string, array<string, string>> preset => token => value (api/resources/design/theme-presets.json) */
    public static function presets(): array
    {
        if (self::$presets === null) {
            $path = resource_path('design/theme-presets.json');
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            if (! is_array($decoded)) {
                throw new RuntimeException('resources/design/theme-presets.json is missing: run `npm run build` in packages/tokens.');
            }

            self::$presets = $decoded;
        }

        return self::$presets;
    }

    /** The preset a mode starts from: the tenant's (Light for the Dark preset) in light mode, Dark in dark mode. */
    public static function baseFor(mixed $preset, string $mode): string
    {
        if ($mode === 'dark') {
            return 'dark';
        }

        return in_array($preset, self::PRESETS, true) && $preset !== 'dark' ? $preset : 'light';
    }

    /**
     * The colour itself when it reaches $ratio on $surface and white or
     * near-black reads on it, else the same hue made lighter (dark
     * surfaces) or darker in OKLCH lightness, by 0.01, until both hold.
     */
    public static function towardsContrast(string $hex, string $surface, float $ratio): string
    {
        $fits = fn (string $c) => Colour::contrastRatio($c, $surface) >= $ratio
            && max(Colour::contrastRatio($c, Colour::ON_LIGHT), Colour::contrastRatio($c, Colour::ON_DARK)) >= self::TEXT;
        $base = Colour::normalise($hex);

        if ($fits($base)) {
            return $base;
        }

        $color = Colour::hexToOklch($base);
        $lighter = Colour::contrastRatio('#ffffff', $surface) > Colour::contrastRatio('#000000', $surface);

        for ($step = 1; $step <= 100; $step++) {
            $l = $lighter ? $color['l'] + $step / 100 : $color['l'] - $step / 100;

            if ($l > 1 || $l < 0) {
                break;
            }

            $candidate = Colour::oklchToHex([...$color, 'l' => $l]);

            if ($fits($candidate)) {
                return $candidate;
            }
        }

        return $lighter ? '#ffffff' : '#000000';
    }

    /** @return array<string, string> the token overrides of $theme in $mode */
    public static function overrides(array $theme, string $mode): array
    {
        $colors = is_array($theme['colors'] ?? null) ? $theme['colors'] : [];
        $dark = $mode === 'dark';
        $surface = self::presets()[self::baseFor($theme['preset'] ?? null, $mode)]['surface-200'];
        $out = [];

        if (self::isHex($colors['primary'] ?? null)) {
            $base = $dark ? self::towardsContrast($colors['primary'], $surface, self::TEXT) : Colour::normalise($colors['primary']);
            $pair = Colour::brandPair($base, $mode);
            $out += ['primary' => $pair['base'], 'primary-hover' => $pair['hover'], 'on-primary' => $pair['on'], 'primary-tint' => $pair['tint']];
        }

        if (self::isHex($colors['accent'] ?? null)) {
            $base = $dark ? self::towardsContrast($colors['accent'], $surface, self::NON_TEXT) : Colour::normalise($colors['accent']);
            $pair = Colour::brandPair($base, $mode);
            $out += ['accent' => $pair['base'], 'accent-hover' => $pair['hover'], 'on-accent' => $pair['on']];
        }

        $sidebar = $theme['sidebar'] ?? null;

        if (! $dark && is_string($sidebar) && isset(self::SIDEBARS[$sidebar])) {
            $source = self::presets()[self::SIDEBARS[$sidebar]];

            foreach (self::SIDEBAR_TOKENS as $token) {
                $out[$token] = $source[$token];
            }
        }

        $corners = $theme['corners'] ?? null;

        if (is_string($corners) && isset(self::CORNERS[$corners])) {
            $out += self::CORNERS[$corners];
        }

        $font = $theme['font'] ?? null;

        if (is_string($font) && isset(self::FONTS[$font])) {
            $out += ['font-sans' => self::FONTS[$font]['sans'], 'font-display' => self::FONTS[$font]['display']];
        }

        return $out;
    }

    /** @return array{light: array<string, string>, dark: array<string, string>} */
    public static function compile(array $theme): array
    {
        return ['light' => self::overrides($theme, 'light'), 'dark' => self::overrides($theme, 'dark')];
    }

    /**
     * Every BR-03 check in both modes. `ratio` is cut to two decimals.
     *
     * @return list<array{mode: string, pair: string, field: string, foreground: string, background: string, ratio: float, required: float, passes: bool}>
     */
    public static function checks(array $theme): array
    {
        $checks = [];

        foreach (self::MODES as $mode) {
            $tokens = [...self::presets()[self::baseFor($theme['preset'] ?? null, $mode)], ...self::overrides($theme, $mode)];

            foreach (self::PAIRS as [$pair, $fg, $bg, $required]) {
                $ratio = Colour::contrastRatio($tokens[$fg], $tokens[$bg]);
                $checks[] = [
                    'mode' => $mode,
                    'pair' => $pair,
                    'field' => self::PAIR_FIELD[$pair],
                    'foreground' => $tokens[$fg],
                    'background' => $tokens[$bg],
                    'ratio' => floor($ratio * 100) / 100,
                    'required' => $required,
                    'passes' => $ratio >= $required,
                ];
            }
        }

        return $checks;
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
