<?php

namespace Tests\Unit\Core\CustomFields;

use App\Core\CustomFields\Formula\Formula;
use App\Core\CustomFields\Formula\FormulaError;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// CF-01: the formula language of formula fields is parsed and evaluated by
// our own code: arithmetic on decimals, comparisons, if(), round(),
// concat() and field references; anything else is refused at parse time.
class FormulaTest extends TestCase
{
    private function value(string $expression, array $values = [], string $type = 'number'): string|bool|null
    {
        return Formula::parse($expression)->evaluate($values, $type);
    }

    public function test_arithmetic_uses_decimals_with_the_usual_precedence(): void
    {
        $this->assertSame('7', $this->value('1 + 2 * 3'));
        $this->assertSame('9', $this->value('(1 + 2) * 3'));
        $this->assertSame('0.3', $this->value('0.1 + 0.2'));
        $this->assertSame('-4', $this->value('-(2 + 2)'));
        $this->assertSame('0.333333333333', $this->value('1 / 3'));
        $this->assertSame('2.5', $this->value('5 / 2'));
    }

    public function test_field_references_read_the_values_given(): void
    {
        $values = ['cost' => BigDecimal::of('120.50'), 'markup' => BigDecimal::of('25'), 'name' => 'Box', 'fragile' => true, 'note' => null];

        $this->assertSame('150.625', $this->value('cost * (1 + markup / 100)', $values));
        $this->assertSame('Box', $this->value('name', $values, 'text'));
        $this->assertTrue($this->value('fragile', $values, 'boolean'));
        $this->assertSame(['cost', 'markup'], Formula::parse('cost * (1 + markup / 100) + cost')->references());
    }

    public function test_comparisons_logic_and_if(): void
    {
        $values = ['qty' => BigDecimal::of('12'), 'kind' => 'bulk'];

        $this->assertTrue($this->value('qty >= 10 and kind = "bulk"', $values, 'boolean'));
        $this->assertFalse($this->value('qty < 10 or kind <> \'bulk\'', $values, 'boolean'));
        $this->assertTrue($this->value('not (qty = 11)', $values, 'boolean'));
        $this->assertSame('Large', $this->value('if(qty > 10, "Large", "Small")', $values, 'text'));
        $this->assertSame('5', $this->value('IF(qty == 12, 5, 6)', $values));
    }

    public function test_round_and_concat(): void
    {
        $this->assertSame('3', $this->value('round(2.5)'));
        $this->assertSame('2.35', $this->value('round(2.345, 2)'));
        $this->assertSame('-3', $this->value('round(-2.5)'));
        $this->assertSame('Box of 12 (true)', $this->value('concat("Box of ", 12, " (", true, ")")', [], 'text'));
        $this->assertSame('a', $this->value('concat("a", missing_value)', ['missing_value' => null], 'text'));
    }

    public function test_nulls_divisions_by_zero_and_type_mismatches_give_null(): void
    {
        $this->assertNull($this->value('cost * 2', ['cost' => null]));
        $this->assertNull($this->value('1 / 0'));
        $this->assertNull($this->value('"a" * 2'));
        $this->assertNull($this->value('"a" < 2', [], 'boolean'));
        $this->assertNull($this->value('round(1.5, 20)'));
        // A reference without a value (not given at all) also gives null.
        $this->assertNull($this->value('ghost + 1'));
        // The result must be of the field's type.
        $this->assertNull($this->value('"text"'));
        $this->assertNull($this->value('1 + 1', [], 'boolean'));
        $this->assertSame('2', $this->value('1 + 1', [], 'text'));
        // A null equals only null.
        $this->assertTrue($this->value('note = null', ['note' => null], 'boolean'));
    }

    public function test_known_fields_are_enforced_when_given(): void
    {
        Formula::parse('cost + 1', ['cost']);

        $this->expectExceptionObject(new FormulaError('unknown_field', ['field' => 'price']));
        Formula::parse('cost + price', ['cost']);
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'empty' => ['   ', 'empty'],
            'php function' => ['system("ls")', 'unknown_function'],
            'eval' => ['eval("1")', 'unknown_function'],
            'dynamic call' => ['("sys" + "tem")("ls")', 'unexpected'],
            'variable' => ['$cost + 1', 'bad_character'],
            'interpolation' => ['"${cost}" + ${x}', 'bad_character'],
            'backticks' => ['`rm -rf /`', 'bad_character'],
            'statement separator' => ['cost; drop table items', 'bad_character'],
            'array access' => ['cost[0]', 'bad_character'],
            'property access' => ['cost.length', 'bad_number_or_character'],
            'static call' => ['DB::select(1)', 'bad_character'],
            'php tag' => ['<?php echo 1 ?>', 'bad_character'],
            'assignment chain' => ['a = b = c', 'chained_comparison'],
            'unclosed text' => ['"abc', 'unclosed_text'],
            'unclosed parenthesis' => ['(1 + 2', 'incomplete'],
            'dangling operator' => ['1 +', 'incomplete'],
            'too many arguments' => ['if(1, 2, 3, 4)', 'arguments'],
            'too few arguments' => ['round()', 'arguments'],
            'upper case reference' => ['Cost + 1', 'unknown_field'],
            'number glued to word' => ['12abc', 'bad_number'],
            'unicode operator' => ['1 × 2', 'bad_character'],
            'too deep' => [str_repeat('(', 40).'1'.str_repeat(')', 40), 'too_deep'],
            'too deep unary' => [str_repeat('-', 40).'1', 'too_deep'],
            'too long' => [str_repeat('1+', 600).'1', 'too_long'],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_outside_the_language_is_refused_at_parse_time(string $expression, string $error): void
    {
        try {
            Formula::parse($expression);
            $this->fail("[{$expression}] was accepted");
        } catch (FormulaError $e) {
            if ($error === 'bad_number_or_character') {
                $this->assertContains($e->key, ['bad_number', 'bad_character']);
            } else {
                $this->assertSame($error, $e->key, "[{$expression}]");
            }
        }
    }

    public function test_a_huge_number_never_breaks_evaluation(): void
    {
        $this->assertSame(str_repeat('9', 30), $this->value(str_repeat('9', 30)));
        $this->assertNotNull($this->value(str_repeat('9', 30).' * '.str_repeat('9', 30)));
    }
}
