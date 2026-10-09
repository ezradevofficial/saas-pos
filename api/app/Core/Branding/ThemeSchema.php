<?php

namespace App\Core\Branding;

use App\Core\Branding\Models\BrandAsset;
use App\Core\Configuration\PayloadSchema;
use Illuminate\Support\Str;

/**
 * BR-02, BR-03: what keeps a theme from being published.
 *
 *   {
 *     "preset": "light" | "dark" | "executive" | "warm",
 *     "colors": { "primary": "#0b5d6e", "accent": "#18181b" },   base colours only
 *     "sidebar": "light" | "dark" | null,
 *     "corners": "sharp" | "standard" | "soft" | null,
 *     "font": "geist" | "ibm_plex_sans" | "newsreader_geist" | null,
 *     "logo_light": asset id, "logo_dark": asset id, "favicon": asset id,
 *     "login": { "background": asset id, "welcome": "text typed once" }
 *   }
 *
 * - Anything else is refused as `not_overridable`: hover, tint and
 *   on-colours are derived (ThemeCompiler), and status colours, spacing,
 *   type sizes and the focus ring are never a tenant's to change.
 * - Asset ids must be the tenant's assets of the right kind.
 * - Every contrast pair must meet WCAG AA in light and dark mode
 *   (`contrast`, one problem per failing pair, BR-03).
 * - `login` is read from the tenant-wide theme only (the sign-in page
 *   comes before any company is known).
 */
final class ThemeSchema
{
    public const WELCOME_MAX = 280;

    private const ASSETS = [
        'logo_light' => BrandAsset::LOGO,
        'logo_dark' => BrandAsset::LOGO,
        'favicon' => BrandAsset::FAVICON,
    ];

    private const SCHEMA = [
        'type' => 'object',
        'required' => ['preset'],
        'properties' => [
            'preset' => ['type' => 'string', 'enum' => ThemeCompiler::PRESETS],
            'colors' => ['type' => 'object', 'properties' => [
                'primary' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
                'accent' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
            ]],
            'sidebar' => ['type' => 'string', 'nullable' => true, 'enum' => ['light', 'dark']],
            'corners' => ['type' => 'string', 'nullable' => true, 'enum' => ['sharp', 'standard', 'soft']],
            'font' => ['type' => 'string', 'nullable' => true, 'enum' => ['geist', 'ibm_plex_sans', 'newsreader_geist']],
            'logo_light' => ['type' => 'string', 'nullable' => true],
            'logo_dark' => ['type' => 'string', 'nullable' => true],
            'favicon' => ['type' => 'string', 'nullable' => true],
            'login' => ['type' => 'object', 'additional' => false, 'properties' => [
                'background' => ['type' => 'string', 'nullable' => true],
                'welcome' => ['type' => 'string', 'nullable' => true, 'max' => self::WELCOME_MAX],
            ]],
        ],
    ];

    /** @return list<array{path: string, code: string, message: string}> */
    public static function problems(array $payload): array
    {
        $problems = [];

        foreach (array_keys($payload) as $key) {
            if (! isset(self::SCHEMA['properties'][$key])) {
                $problems[] = PayloadSchema::problem((string) $key, 'not_overridable');
            }
        }

        if (is_array($payload['colors'] ?? null)) {
            foreach (array_keys($payload['colors']) as $key) {
                if (! in_array($key, ['primary', 'accent'], true)) {
                    $problems[] = PayloadSchema::problem("colors.{$key}", 'not_overridable');
                }
            }
        }

        $known = array_intersect_key($payload, self::SCHEMA['properties']);

        if (is_array($known['colors'] ?? null)) {
            $known['colors'] = array_intersect_key($known['colors'], ['primary' => true, 'accent' => true]);
        }

        $structure = PayloadSchema::check($known, self::SCHEMA);
        array_push($problems, ...$structure);

        array_push($problems, ...self::assetProblems($payload));

        // Contrast only when the colours and choices are well formed.
        if ($structure === []) {
            foreach (ThemeCompiler::checks($known) as $check) {
                if (! $check['passes']) {
                    $problems[] = PayloadSchema::problem($check['field'], 'contrast', [
                        'mode' => __('config.theme.modes.'.$check['mode']),
                        'pair' => __('config.theme.pairs.'.$check['pair']),
                        'ratio' => number_format($check['ratio'], 2),
                        'required' => number_format($check['required'], 1),
                    ]) + ['mode' => $check['mode'], 'pair' => $check['pair']];
                }
            }
        }

        return $problems;
    }

    /** @return list<array{path: string, code: string, message: string}> */
    private static function assetProblems(array $payload): array
    {
        $wanted = [];

        foreach (self::ASSETS as $field => $kind) {
            if (is_string($payload[$field] ?? null)) {
                $wanted[$field] = [$payload[$field], $kind];
            }
        }

        if (is_array($payload['login'] ?? null) && is_string($payload['login']['background'] ?? null)) {
            $wanted['login.background'] = [$payload['login']['background'], BrandAsset::BACKGROUND];
        }

        if ($wanted === []) {
            return [];
        }

        $ids = array_values(array_filter(array_column($wanted, 0), fn (string $id) => Str::isUuid($id)));
        $found = $ids === [] ? [] : BrandAsset::query()->whereIn('id', $ids)->pluck('kind', 'id')->all();
        $problems = [];

        foreach ($wanted as $field => [$id, $kind]) {
            if (($found[$id] ?? null) !== $kind) {
                $problems[] = PayloadSchema::problem($field, 'asset_missing');
            }
        }

        return $problems;
    }
}
