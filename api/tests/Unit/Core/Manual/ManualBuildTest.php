<?php

namespace Tests\Unit\Core\Manual;

use App\Core\Manual\ManualBuilder;
use App\Core\Manual\TopicLibrary;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// `manual:build`: a guide per role template and locale, as a valid PDF.
class ManualBuildTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // The PDFs go to storage/app/manual; this test writes to a folder of its own.
        $this->storage = sys_get_temp_dir().'/manual-build-test-'.bin2hex(random_bytes(4));
        mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);
        config(['app.name' => 'Test App']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    #[DataProvider('locales')]
    public function test_a_cashier_guide_is_a_valid_pdf_with_every_topic_title(string $locale): void
    {
        $pdf = app(ManualBuilder::class)->pdf($locale, 'cashier');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));

        $text = $this->pageText($pdf);
        $topics = app(TopicLibrary::class)->forRole($locale, 'cashier');

        $this->assertGreaterThan(10, $topics->count());

        foreach ($topics as $topic) {
            $this->assertStringContainsString($this->encode($topic->title), $text, "The {$locale} cashier guide lacks the title [{$topic->title}].");
        }

        // The contents and the numbered pages are there, and the cover is not numbered.
        $this->assertStringContainsString($this->encode($locale === 'fr' ? 'Sommaire' : 'Contents'), $text);
        $this->assertStringContainsString('Page 2', $text);
        $this->assertStringNotContainsString('Page 1)', $text);
        $this->assertStringContainsString('Test App', $text);
    }

    public static function locales(): array
    {
        return [['en'], ['fr']];
    }

    public function test_the_app_name_replaces_the_placeholder(): void
    {
        $html = app(ManualBuilder::class)->html('en', 'cashier');

        $this->assertStringContainsString('Test App', $html);
        $this->assertStringNotContainsString('{{app_name}}', $html);
    }

    public function test_a_guide_groups_topics_by_module_with_core_first(): void
    {
        $html = app(ManualBuilder::class)->html('en', 'cashier');

        $this->assertLessThan(strpos($html, 'id="m-pos"'), strpos($html, 'id="m-core"'));
    }

    public function test_the_command_writes_one_pdf_per_locale_and_role(): void
    {
        $this->artisan('manual:build', ['--role' => 'cashier'])->assertExitCode(0);

        foreach (['en', 'fr'] as $locale) {
            $path = $this->storage."/app/manual/{$locale}/cashier.pdf";

            $this->assertFileExists($path);
            $this->assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        }
    }

    public function test_the_command_builds_every_topic_with_all(): void
    {
        $this->artisan('manual:build', ['--all' => true, '--locale' => 'en'])->assertExitCode(0);

        $this->assertFileExists($this->storage.'/app/manual/en/all.pdf');
        $this->assertFileDoesNotExist($this->storage.'/app/manual/fr/all.pdf');
    }

    public function test_the_command_refuses_a_role_that_is_not_a_template_and_a_missing_choice(): void
    {
        $this->artisan('manual:build', ['--role' => 'nonsense'])->assertExitCode(1);
        $this->artisan('manual:build')->assertExitCode(1);
        $this->artisan('manual:build', ['--role' => 'cashier', '--all' => true])->assertExitCode(1);
        $this->artisan('manual:build', ['--role' => 'cashier', '--locale' => 'de'])->assertExitCode(1);
    }

    public function test_an_unknown_placeholder_fails_the_build(): void
    {
        $directory = $this->storage.'/topics/en/core';
        mkdir($directory, 0775, true);
        file_put_contents($directory.'/bad.md', "---\nid: bad\ntitle: Bad\nmodule: core\npermissions: []\nroles: [owner]\nscreens: []\n---\nWelcome to {{product}}.\n");

        $builder = new ManualBuilder(new TopicLibrary($this->storage.'/topics'), '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only {{app_name}} is known');

        $builder->html('en', 'owner');
    }

    /** The text of every page: all compressed content streams, inflated. */
    private function pageText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        return implode("\n", array_map(fn (string $stream) => (string) @gzuncompress($stream), $streams[1]));
    }

    /** A title as the core PDF fonts store it: Windows-1252, with octal escapes for the high bytes. */
    private function encode(string $text): string
    {
        return mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }
}
