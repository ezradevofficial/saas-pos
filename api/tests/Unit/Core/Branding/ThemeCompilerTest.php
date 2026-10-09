<?php

namespace Tests\Unit\Core\Branding;

use App\Core\Branding\Colour;
use App\Core\Branding\ThemeCompiler;
use Tests\TestCase;

/**
 * BR-02, BR-03: the PHP port computes exactly what packages/tokens
 * computes, on the vectors both sides share
 * (packages/tokens/test/fixtures/brand-vectors.json).
 */
class ThemeCompilerTest extends TestCase
{
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(base_path('../packages/tokens/test/fixtures/brand-vectors.json')), true);
    }

    public function test_contrast_ratios_match_the_shared_vectors(): void
    {
        foreach (self::vectors()['contrast'] as $v) {
            $this->assertEqualsWithDelta($v['ratio'], Colour::contrastRatio($v['a'], $v['b']), 1e-10, "{$v['a']} on {$v['b']}");
        }
    }

    public function test_derived_pairs_match_the_shared_vectors(): void
    {
        foreach (self::vectors()['derive'] as $v) {
            $this->assertSame($v['pair'], Colour::brandPair($v['hex'], $v['mode']), "{$v['hex']} {$v['mode']}");
        }
    }

    public function test_colours_lifted_for_contrast_match_the_shared_vectors(): void
    {
        foreach (self::vectors()['towards'] as $v) {
            $this->assertSame($v['result'], ThemeCompiler::towardsContrast($v['hex'], $v['surface'], (float) $v['ratio']), "{$v['hex']} on {$v['surface']} at {$v['ratio']}");
        }
    }

    public function test_compiled_themes_and_failing_checks_match_the_shared_vectors(): void
    {
        foreach (self::vectors()['themes'] as $v) {
            $theme = $v['theme'];
            $this->assertSame($v['compiled'], ThemeCompiler::compile($theme), json_encode($theme));

            $failing = array_values(array_map(
                fn (array $c) => "{$c['mode']}:{$c['pair']}:".self::js($c['ratio']),
                array_filter(ThemeCompiler::checks($theme), fn (array $c) => ! $c['passes']),
            ));
            $this->assertSame($v['failing'], $failing, json_encode($theme));
        }
    }

    public function test_only_overridable_tokens_are_ever_set(): void
    {
        $compiled = ThemeCompiler::compile([
            'preset' => 'warm',
            'colors' => ['primary' => '#0b5d6e', 'accent' => '#7c2d12', 'danger' => '#00ff00', 'primary-hover' => '#ff0000'],
            'focus' => '#00ff00', 'sidebar' => 'dark', 'corners' => 'soft', 'font' => 'newsreader_geist',
        ]);

        foreach ($compiled as $tokens) {
            $this->assertSame([], array_diff(array_keys($tokens), ThemeCompiler::OVERRIDABLE));
        }

        $this->assertNotSame('#ff0000', $compiled['light']['primary-hover']);
    }

    public function test_the_presets_copy_matches_design_tokens(): void
    {
        $source = json_decode((string) file_get_contents(base_path('../design/tokens.json')), true);
        $presets = ThemeCompiler::presets();

        foreach ($source['color']['tokens'] as $token) {
            foreach (ThemeCompiler::PRESETS as $preset) {
                $value = $token['value'][$preset] ?? $token['value']['light'] ?? null;

                if (is_string($value) && str_starts_with($value, '#')) {
                    $this->assertSame($value, $presets[$preset][$token['name']] ?? null, "{$preset} {$token['name']}: run npm run build in packages/tokens");
                }
            }
        }
    }

    /** A float as JavaScript prints it (4.0 is "4"). */
    private static function js(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
