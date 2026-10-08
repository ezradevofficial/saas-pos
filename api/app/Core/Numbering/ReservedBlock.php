<?php

namespace App\Core\Numbering;

/**
 * Counter values $from..$to reserved in one move of a sequence (NUM-02),
 * with the pattern frozen for them: place codes and, when the format
 * resets yearly, the period's year are filled in; {MM} (and the year of a
 * format that never resets) stay for the document's own date.
 */
final class ReservedBlock
{
    public function __construct(
        public readonly string $formatId,
        public readonly string $sequenceId,
        public readonly string $period,
        public readonly int $from,
        public readonly int $to,
        public readonly string $pattern,
    ) {}
}
