<?php

namespace Tests\Unit\Core\Numbering;

use App\Core\Numbering\Pattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// NUM-01: pattern tokens, padding and validation.
class PatternTest extends TestCase
{
    public function test_it_renders_tokens_and_pads_the_counter(): void
    {
        $pattern = Pattern::parse('PO-{BRANCH}-{YYYY}-{00001}');

        $this->assertSame(['BRANCH', 'YYYY'], $pattern->tokens);
        $this->assertSame(5, $pattern->width);
        $this->assertSame('PO-NRB-2026-00042', $pattern->render(['BRANCH' => 'NRB', 'YYYY' => '2026'], 42));
        // A counter wider than its zeros is printed in full.
        $this->assertSame('PO-NRB-2026-123456', $pattern->render(['BRANCH' => 'NRB', 'YYYY' => '2026'], 123456));
    }

    public function test_with_freezes_some_tokens_and_keeps_the_rest(): void
    {
        $frozen = Pattern::parse('R-{LOCATION}-{YY}{MM}-{000001}')->with(['LOCATION' => 'L01', 'YY' => '26']);

        $this->assertSame('R-L01-26{MM}-{000001}', $frozen->pattern);
        $this->assertSame('R-L01-2610-000007', $frozen->render(['MM' => '10'], 7));
    }

    public function test_a_missing_value_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Pattern::parse('{BRANCH}-{001}')->render([], 1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalid(): array
    {
        return [
            'empty' => ['', 'core.numbering.errors.pattern_length'],
            'too long' => [str_repeat('A', 56).'{00001}', 'core.numbering.errors.pattern_length'],
            'no counter' => ['PO-{YYYY}', 'core.numbering.errors.pattern_counter'],
            'two counters' => ['{001}-{001}', 'core.numbering.errors.pattern_counter'],
            'unknown token' => ['{COMPANY}-{001}', 'core.numbering.errors.pattern_token'],
            'counter too wide' => ['{0000000000001}', 'core.numbering.errors.pattern_characters'],
            'space' => ['PO {001}', 'core.numbering.errors.pattern_characters'],
            'brace' => ['PO-{001', 'core.numbering.errors.pattern_characters'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_patterns_are_refused(string $pattern, string $key): void
    {
        try {
            Pattern::parse($pattern);
            $this->fail("{$pattern} was accepted");
        } catch (InvalidArgumentException $e) {
            $this->assertSame($key, $e->getMessage());
        }
    }
}
