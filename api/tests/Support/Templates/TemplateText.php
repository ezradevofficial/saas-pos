<?php

namespace Tests\Support\Templates;

/**
 * TPL-01: rendered template HTML reduced to its text, one line per block,
 * row or cell group, so the PHP renderer and the till's JS port can be
 * compared (pos/src/pos/templates/templateText.js does the same steps).
 */
final class TemplateText
{
    public static function of(string $html): string
    {
        $html = (string) preg_replace('#<(style|title)[^>]*>.*?</\1>#s', '', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(div|tr|p|thead|tbody|table)>#i', "\n", $html);
        $html = (string) preg_replace('#</t[dh]>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(fn (string $line) => trim((string) preg_replace('/\s+/u', ' ', $line)), explode("\n", $text));

        return implode("\n", array_values(array_filter($lines, fn (string $line) => $line !== '')));
    }

    /** @return list<string> the shared fixture files */
    public static function fixtures(): array
    {
        return glob(base_path('tests/Fixtures/templates/*.json')) ?: [];
    }
}
