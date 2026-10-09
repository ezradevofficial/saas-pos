<?php

namespace App\Core\Numbering;

use InvalidArgumentException;

/**
 * A number format's pattern (NUM-01), e.g. `PO-{BRANCH}-{YYYY}-{00001}`.
 *
 * Tokens: {BRANCH}, {LOCATION}, {DEVICE} (codes of the document's place),
 * {YYYY}, {YY}, {MM} (the document's date in its local time zone) and
 * exactly one counter, zeros then a 1 ({00001}), whose length gives the
 * minimum width (1 to 12).
 * Literal text is letters, digits and `- / _ .`. A counter wider than its
 * zeros is printed in full, never cut.
 */
final class Pattern
{
    public const MAX_LENGTH = 60;

    public const PLACE_TOKENS = ['BRANCH', 'LOCATION', 'DEVICE'];

    public const DATE_TOKENS = ['YYYY', 'YY', 'MM'];

    public const YEAR_TOKENS = ['YYYY', 'YY'];

    private const TOKEN = '/\{([A-Z]+|0{0,11}1)\}/';

    /** @param list<string> $tokens named tokens used, in order (counter excluded) */
    private function __construct(
        public readonly string $pattern,
        public readonly array $tokens,
        public readonly int $width,
    ) {}

    /** @throws InvalidArgumentException with a translation key as message */
    public static function parse(string $pattern, bool $limitLength = true): self
    {
        if ($pattern === '' || ($limitLength && mb_strlen($pattern) > self::MAX_LENGTH)) {
            throw new InvalidArgumentException('core.numbering.errors.pattern_length');
        }

        $literal = preg_replace(self::TOKEN, '', $pattern);

        if (preg_match('#^[A-Za-z0-9\-/_.]*$#', (string) $literal) !== 1) {
            throw new InvalidArgumentException('core.numbering.errors.pattern_characters');
        }

        preg_match_all(self::TOKEN, $pattern, $matches);
        $tokens = [];
        $widths = [];

        foreach ($matches[1] as $token) {
            if (preg_match('/^0*1$/', $token) === 1) {
                $widths[] = strlen($token);
            } elseif (in_array($token, [...self::PLACE_TOKENS, ...self::DATE_TOKENS], true)) {
                $tokens[] = $token;
            } else {
                throw new InvalidArgumentException('core.numbering.errors.pattern_token');
            }
        }

        if (count($widths) !== 1) {
            throw new InvalidArgumentException('core.numbering.errors.pattern_counter');
        }

        return new self($pattern, $tokens, $widths[0]);
    }

    public function uses(string $token): bool
    {
        return in_array($token, $this->tokens, true);
    }

    public function hasYear(): bool
    {
        return array_intersect(self::YEAR_TOKENS, $this->tokens) !== [];
    }

    /**
     * The pattern with $values substituted (token => text); tokens not in
     * $values and the counter are kept. Used to freeze a device range's
     * place codes and period (NUM-02).
     *
     * @param  array<string, string>  $values
     */
    public function with(array $values): self
    {
        $text = preg_replace_callback(self::TOKEN, fn (array $m) => $values[$m[1]] ?? $m[0], $this->pattern);

        // Filled-in codes may make it longer than a typed pattern may be.
        return self::parse((string) $text, limitLength: false);
    }

    /**
     * The number: every named token from $values (all must be given), the
     * counter zero-padded to the pattern's width.
     *
     * @param  array<string, string>  $values
     */
    public function render(array $values, int $counter): string
    {
        if ($counter < 1) {
            throw new InvalidArgumentException('A counter starts at 1.');
        }

        return (string) preg_replace_callback(self::TOKEN, function (array $m) use ($values, $counter) {
            if (preg_match('/^0*1$/', $m[1]) === 1) {
                return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
            }

            return $values[$m[1]] ?? throw new InvalidArgumentException("No value for {{$m[1]}}.");
        }, $this->pattern);
    }
}
