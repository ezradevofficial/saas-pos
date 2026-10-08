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

    public function test_json_translations_have_the_same_keys_and_cover_the_mail_template(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $fr = json_decode(file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($en), array_keys($fr));

        // Every string Laravel's mail and notification templates translate.
        $templates = '';
        foreach (['Notifications', 'Mail'] as $package) {
            foreach (glob(base_path("vendor/laravel/framework/src/Illuminate/{$package}/resources/views/{,*/}*/*.blade.php"), GLOB_BRACE) ?: [] as $file) {
                $templates .= file_get_contents($file);
            }
            foreach (glob(base_path("vendor/laravel/framework/src/Illuminate/{$package}/resources/views/*.blade.php")) ?: [] as $file) {
                $templates .= file_get_contents($file);
            }
        }
        preg_match_all("/(?:@lang|__)\\(\\s*'([^']+)'\\s*\\)/", $templates, $plain);
        $this->assertGreaterThanOrEqual(4, count(array_unique($plain[1])));
        foreach (array_unique($plain[1]) as $key) {
            $this->assertArrayHasKey($key, $fr, "fr.json lacks [{$key}]");
        }
        $this->assertArrayHasKey("If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:", $fr);

        foreach ($fr as $key => $value) {
            $this->assertNotSame('', trim($value), "fr.json [{$key}] is empty");
            $this->assertNotSame($key, $value, "fr.json [{$key}] is not translated");
            $this->assertStringNotContainsString('!', $value, "fr.json [{$key}] has an exclamation mark");
            $this->assertStringNotContainsString('!', $en[$key], "en.json [{$key}] has an exclamation mark");
        }
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
