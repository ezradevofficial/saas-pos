<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class TranslationParityTest extends TestCase
{
    /** @return list<string> */
    private function keys(string $locale): array
    {
        $keys = [];

        foreach (glob(lang_path("{$locale}/*.php")) as $file) {
            $group = basename($file, '.php');

            foreach (array_keys(Arr::dot(require $file)) as $key) {
                $keys[] = "{$group}.{$key}";
            }
        }

        sort($keys);

        return $keys;
    }

    public function test_english_and_french_have_the_same_keys(): void
    {
        $en = $this->keys('en');

        $this->assertNotEmpty($en);
        $this->assertSame($en, $this->keys('fr'));
    }

    public function test_every_group_exists_in_both_locales(): void
    {
        foreach (['app', 'auth', 'validation', 'core', 'rbac'] as $group) {
            $this->assertFileExists(lang_path("en/{$group}.php"));
            $this->assertFileExists(lang_path("fr/{$group}.php"));
        }
    }

    public function test_french_values_are_not_empty(): void
    {
        foreach (glob(lang_path('fr/*.php')) as $file) {
            foreach (Arr::dot(require $file) as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertNotSame('', trim((string) $value), "fr {$file} {$key} is empty");
            }
        }
    }

    public function test_app_name_comes_from_config(): void
    {
        config(['app.name' => 'Test app']);

        $this->assertSame(config('app.name'), __('app.name', [], 'en'));
    }

    public function test_default_branch_and_location_names_are_translated(): void
    {
        $this->assertSame('Main branch', __('core.defaults.branch', [], 'en'));
        $this->assertSame('Succursale principale', __('core.defaults.branch', [], 'fr'));
        $this->assertSame('Main outlet', __('core.defaults.location', [], 'en'));
        $this->assertSame('Point de vente principal', __('core.defaults.location', [], 'fr'));
    }
}
