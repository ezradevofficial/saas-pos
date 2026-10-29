<?php

namespace App\Core\Search;

/**
 * LAY-01: what the user typed, in the shapes searchers need. Patterns are
 * built here so every searcher escapes the same way: LIKE wildcards are
 * escaped, regex characters are quoted, and letters match their accented
 * forms (case and accent insensitive without a database extension).
 */
final class SearchTerm
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /** Base letter => the accented forms it also matches (lower case; upper case is added). */
    private const FOLD = [
        'a' => 'àáâãäåāăą', 'c' => 'çćĉċč', 'e' => 'èéêëēĕėęě', 'i' => 'ìíîïĩīĭį', 'n' => 'ñńņň',
        'o' => 'òóôõöøōŏő', 's' => 'śŝşš', 'u' => 'ùúûüũūŭůűų', 'y' => 'ýÿ', 'z' => 'źżž',
    ];

    /** @var array<string, string>|null accented letter => base letter */
    private static ?array $base = null;

    public readonly string $text;

    public function __construct(string $text, public readonly int $limit = 5)
    {
        $this->text = trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** `%text%` with LIKE wildcards escaped, for ILIKE (codes and numbers). */
    public function contains(): string
    {
        return '%'.$this->escaped().'%';
    }

    /** `text%` with LIKE wildcards escaped, for LIKE on a prefix. */
    public function prefix(): string
    {
        return $this->escaped().'%';
    }

    private function escaped(): string
    {
        return addcslashes($this->text, '\\%_');
    }

    /** The digits typed, for phone matches ('' when none). */
    public function digits(): string
    {
        return (string) preg_replace('/\D/', '', $this->text);
    }

    /** True when it looks like a phone number: only digits and phone punctuation, 4 digits or more. */
    public function looksLikePhone(): bool
    {
        return preg_match('/^[\d\s+().-]+$/', $this->text) === 1 && strlen($this->digits()) >= 4;
    }

    public function looksLikeEmail(): bool
    {
        return str_contains($this->text, '@');
    }

    /**
     * A regular expression for PostgreSQL's `~*`: the text with every letter
     * matching its accented forms, regex characters quoted and spaces
     * matching any run of spaces. pg_trgm indexes serve it.
     */
    public function regex(bool $anchored = false): string
    {
        $out = '';

        foreach (mb_str_split(mb_strtolower($this->text)) as $char) {
            $base = self::base()[$char] ?? $char;

            if (isset(self::FOLD[$base])) {
                $forms = $base.self::FOLD[$base];
                $out .= '['.$forms.mb_strtoupper($forms).']';
            } elseif (preg_match('/\s/u', $char) === 1) {
                $out .= '\s+';
            } elseif (preg_match('/[\p{L}\p{N}]/u', $char) === 1) {
                $out .= $char;
            } else {
                $out .= '\\'.$char;
            }
        }

        return ($anchored ? '^' : '').$out;
    }

    /** @return array<string, string> */
    private static function base(): array
    {
        if (self::$base === null) {
            self::$base = [];

            foreach (self::FOLD as $letter => $forms) {
                foreach (mb_str_split($forms) as $form) {
                    self::$base[$form] = $letter;
                }
            }
        }

        return self::$base;
    }
}
