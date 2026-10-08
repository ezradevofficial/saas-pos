<?php

namespace App\Core\Numbering;

/**
 * A document type that is numbered (NUM-01), registered by its module:
 * the default format, the place tokens its documents can fill, and
 * whether devices draw its numbers from pre-allocated ranges (NUM-02).
 * A ranged type is never gapless: an unused part of a range is a gap.
 */
final class DocumentNumberType
{
    /** @param list<string> $placeTokens subset of Pattern::PLACE_TOKENS its documents know */
    public function __construct(
        public readonly string $key,
        public readonly string $module,
        public readonly string $defaultPattern,
        public readonly string $defaultReset = NumberFormat::RESET_NEVER,
        public readonly array $placeTokens = ['BRANCH'],
        public readonly bool $ranged = false,
        public readonly string $langKey = '',
    ) {}

    public function name(): string
    {
        return __($this->langKey !== '' ? $this->langKey : "core.numbering.types.{$this->key}");
    }
}
