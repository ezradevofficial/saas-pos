<?php

namespace Tests\Feature\Core\DocumentTemplates;

use App\Core\DocumentTemplates\TemplateRenderer;
use Tests\Support\Templates\TemplateText;
use Tests\TestCase;

/**
 * TPL-01..TPL-03: the shared fixtures (tests/Fixtures/templates) render to
 * their expected text on the server; the till's Jest test renders the same
 * files with the JS port and compares with the same text. The printed
 * wording bundled with the till matches the API's (`templates.print`).
 */
class TemplateFixturesTest extends TestCase
{
    public function test_every_shared_fixture_renders_to_its_expected_text(): void
    {
        $this->assertNotEmpty(TemplateText::fixtures());

        foreach (TemplateText::fixtures() as $file) {
            $fixture = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $html = app(TemplateRenderer::class)->html($fixture['type'], $fixture['template'], $fixture['data'], $fixture['fiscal_required']);
            $expected = (string) file_get_contents(substr($file, 0, -5).'.expected.txt');

            $this->assertSame(rtrim($expected, "\n"), TemplateText::of($html), basename($file));
        }
    }

    public function test_printed_documents_are_black_on_white_escaped_and_without_theme_tokens(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/receipt-80mm-en.json')), true);
        $html = app(TemplateRenderer::class)->html($fixture['type'], $fixture['template'], $fixture['data'], true);

        $this->assertStringContainsString('background: #fff; color: #000;', $html);
        $this->assertStringNotContainsString('var(--', $html);
        $this->assertStringContainsString('Duka &quot;Wholesale&quot; Ltd', $html);
        $this->assertStringNotContainsString('Duka "Wholesale"', $html);
        // The accepted fiscal answer carries its QR code (bacon), as an SVG image.
        $this->assertStringContainsString('<img src="data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('@page { size: 80mm auto;', $html);
    }

    public function test_locked_totals_are_added_and_svg_logos_never_reach_dompdf(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/templates/receipt-locked-blocks.json')), true);
        $html = app(TemplateRenderer::class)->html($fixture['type'], $fixture['template'], $fixture['data'], true);

        // TPL-03: the totals with the tax lines, before the fiscal block, though the template has none.
        $this->assertMatchesRegularExpression('/VAT 16%.*Total.*KRA eTIMS/s', TemplateText::of($html));
        $this->assertStringNotContainsString('svg+xml', $html);
        // Not required: nothing is added.
        $plain = app(TemplateRenderer::class)->html($fixture['type'], $fixture['template'], $fixture['data'], false);
        $this->assertSame(1, substr_count(TemplateText::of($plain), 'KRA eTIMS'));
        $this->assertStringNotContainsString('VAT 16%', TemplateText::of($plain));
    }

    public function test_the_tills_bundled_wording_matches_the_api(): void
    {
        foreach (['en', 'fr'] as $language) {
            $pos = json_decode((string) file_get_contents(base_path("../pos/src/locales/{$language}.json")), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(__('templates.print', [], $language), $pos['templates']['print'] ?? null, "pos/src/locales/{$language}.json templates.print");
        }
    }
}
