<?php

namespace App\Core\Notifications\Templates;

/**
 * NOT-03: `{placeholder}` templates. A placeholder is `{name}` with a
 * lower-case name; any other brace is plain text. Templates are plain
 * text: the HTML of an email escapes the whole rendered text, values
 * included, so neither a template nor a value can inject markup.
 */
final class TemplateRenderer
{
    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)\}/';

    /** @return list<string> the placeholder names $template uses, in order of first use */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string> the placeholders $template uses that are not in $allowed
     */
    public static function unknown(string $template, array $allowed): array
    {
        return array_values(array_diff(self::placeholders($template), $allowed));
    }

    /**
     * Plain text: each `{name}` replaced by its value; a placeholder with
     * no value renders empty, so a missing value never shows as `{name}`.
     *
     * @param  array<string, mixed>  $values
     */
    public static function render(string $template, array $values): string
    {
        return preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($values) {
            $value = $values[$match[1]] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }

    /** One line: runs of whitespace (line breaks too) become one space. */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** At most $max characters (never splitting a character), ending in an ellipsis when cut. */
    public static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /** Rendered plain text as HTML: escaped, line breaks kept. */
    public static function toHtml(string $text): string
    {
        return nl2br(e($text), false);
    }
}
