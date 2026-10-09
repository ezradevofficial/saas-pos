<?php

namespace App\Core\CustomFields\Formula;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/**
 * CF-01: the safe expression language of formula fields. A formula is
 * tokenised and parsed here into a small tree of arrays, then walked by
 * evaluate(). Nothing is ever handed to PHP as code (no eval, no dynamic
 * calls, no variable functions): the only operations are the ones listed
 * below, each implemented in this class.
 *
 *   literals     12, 12.5, 'text', "text", true, false, null
 *   references   another field's key, e.g. `cost` (lower case slug)
 *   arithmetic   + - * / and unary minus (decimals, never floats)
 *   comparisons  = == != <> < <= > >=
 *   logic        and, or, not
 *   functions    if(condition, then, else), round(number[, digits]),
 *                concat(value, ...)
 *
 * Limits keep a formula cheap: MAX_LENGTH characters, MAX_TOKENS tokens,
 * MAX_DEPTH nesting. A null operand gives null (arithmetic, ordering), a
 * division by zero or a type mismatch gives null at evaluation, so saving
 * a record never fails because of its formula.
 */
final class Formula
{
    public const MAX_LENGTH = 1000;

    public const MAX_TOKENS = 300;

    public const MAX_DEPTH = 32;

    public const MAX_TEXT = 1000;

    public const FUNCTIONS = ['if', 'round', 'concat'];

    public const OPERATORS = ['+', '-', '*', '/', '=', '!=', '<', '<=', '>', '>=', 'and', 'or', 'not'];

    private const DIVISION_SCALE = 12;

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $at = 0;

    /** @param array<int, mixed> $tree */
    private function __construct(public readonly string $expression, public readonly array $tree) {}

    /**
     * Parse $expression; with $known, every reference must be one of those keys.
     *
     * @param  list<string>|null  $known
     *
     * @throws FormulaError
     */
    public static function parse(string $expression, ?array $known = null): self
    {
        $expression = trim($expression);

        if ($expression === '') {
            throw new FormulaError('empty');
        }

        if (mb_strlen($expression) > self::MAX_LENGTH) {
            throw new FormulaError('too_long', ['max' => self::MAX_LENGTH]);
        }

        $parser = new self($expression, []);
        $parser->tokens = self::tokenise($expression);
        $tree = $parser->expression(0);

        if ($parser->peek()[0] !== 'end') {
            throw new FormulaError('unexpected', ['token' => (string) $parser->peek()[1]]);
        }

        $formula = new self($expression, $tree);

        if ($known !== null) {
            foreach ($formula->references() as $reference) {
                if (! in_array($reference, $known, true)) {
                    throw new FormulaError('unknown_field', ['field' => $reference]);
                }
            }
        }

        return $formula;
    }

    /** @return list<string> the field keys the formula reads, once each */
    public function references(): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            match ($node[0]) {
                'ref' => $found[$node[1]] = true,
                'un' => $walk($node[2]),
                'bin' => [$walk($node[2]), $walk($node[3])],
                'call' => array_map($walk, $node[2]),
                default => null,
            };
        };
        $walk($this->tree);

        return array_keys($found);
    }

    /**
     * The formula's value from $values (key => BigDecimal|string|bool|null),
     * coerced to $type (number: a decimal string; text: a string; boolean).
     * Any problem while computing gives null.
     *
     * @param  array<string, BigDecimal|string|bool|null>  $values
     */
    public function evaluate(array $values, string $type = 'number'): string|bool|null
    {
        try {
            $result = $this->node($this->tree, $values);
        } catch (FormulaError) {
            return null;
        } catch (Throwable) {
            // Brick math overflow and the like: never a failed save.
            return null;
        }

        return match ($type) {
            'number' => $result instanceof BigDecimal ? (string) $result->strippedOfTrailingZeros() : null,
            'boolean' => is_bool($result) ? $result : null,
            default => $result === null ? null : mb_substr(self::text($result), 0, self::MAX_TEXT),
        };
    }

    // ---- Tokeniser --------------------------------------------------------

    /** @return list<array{0: string, 1: mixed}> */
    private static function tokenise(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if ($char >= '0' && $char <= '9') {
                preg_match('/\G\d{1,30}(\.\d{1,18})?/', $source, $match, 0, $i);

                if ($match === [] || ($i + strlen($match[0]) < $length && (preg_match('/[A-Za-z_]/', $source[$i + strlen($match[0])]) === 1 || $source[$i + strlen($match[0])] === '.'))) {
                    throw new FormulaError('bad_number');
                }

                $tokens[] = ['num', BigDecimal::of($match[0])];
                $i += strlen($match[0]);
            } elseif ($char === '"' || $char === "'") {
                [$text, $i] = self::string($source, $i);
                $tokens[] = ['str', $text];
            } elseif (preg_match('/[A-Za-z_]/', $char) === 1) {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]{0,63}/', $source, $match, 0, $i);
                $i += strlen($match[0]);
                $word = $match[0];
                $lower = strtolower($word);

                $tokens[] = match (true) {
                    in_array($lower, ['and', 'or', 'not'], true) => ['op', $lower],
                    $lower === 'true' => ['bool', true],
                    $lower === 'false' => ['bool', false],
                    $lower === 'null' => ['null', null],
                    default => ['id', $word],
                };
            } else {
                $two = substr($source, $i, 2);

                if (in_array($two, ['<=', '>=', '!=', '<>', '=='], true)) {
                    $tokens[] = ['op', match ($two) {
                        '<>' => '!=',
                        '==' => '=',
                        default => $two,
                    }];
                    $i += 2;
                } elseif (in_array($char, ['+', '-', '*', '/', '<', '>', '='], true)) {
                    $tokens[] = ['op', $char];
                    $i++;
                } elseif (in_array($char, ['(', ')', ','], true)) {
                    $tokens[] = [$char, $char];
                    $i++;
                } else {
                    // Anything else ($, ;, backticks, brackets, ...) is not part of the language.
                    throw new FormulaError('bad_character', ['character' => mb_substr(substr($source, $i), 0, 1)]);
                }
            }

            if (count($tokens) > self::MAX_TOKENS) {
                throw new FormulaError('too_long', ['max' => self::MAX_LENGTH]);
            }
        }

        $tokens[] = ['end', ''];

        return $tokens;
    }

    /** @return array{0: string, 1: int} the string's text and the index after it */
    private static function string(string $source, int $start): array
    {
        $quote = $source[$start];
        $text = '';
        $i = $start + 1;
        $length = strlen($source);

        while ($i < $length) {
            $char = $source[$i];

            if ($char === '\\' && $i + 1 < $length && in_array($source[$i + 1], [$quote, '\\'], true)) {
                $text .= $source[$i + 1];
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                return [$text, $i + 1];
            }

            $text .= $char;
            $i++;
        }

        throw new FormulaError('unclosed_text');
    }

    // ---- Parser (precedence climbing) ------------------------------------

    /** @return array{0: string, 1: mixed} */
    private function peek(): array
    {
        return $this->tokens[$this->at];
    }

    /** @return array{0: string, 1: mixed} */
    private function take(): array
    {
        return $this->tokens[$this->at++];
    }

    private function expect(string $kind): void
    {
        if ($this->peek()[0] !== $kind) {
            throw new FormulaError($this->peek()[0] === 'end' ? 'incomplete' : 'unexpected', ['token' => (string) $this->peek()[1]]);
        }

        $this->at++;
    }

    private function enter(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new FormulaError('too_deep', ['max' => self::MAX_DEPTH]);
        }
    }

    /** or */
    private function expression(int $depth): array
    {
        $this->enter($depth);
        $left = $this->conjunction($depth);

        while ($this->peek() === ['op', 'or']) {
            $this->take();
            $left = ['bin', 'or', $left, $this->conjunction($depth)];
        }

        return $left;
    }

    /** and */
    private function conjunction(int $depth): array
    {
        $left = $this->negation($depth);

        while ($this->peek() === ['op', 'and']) {
            $this->take();
            $left = ['bin', 'and', $left, $this->negation($depth)];
        }

        return $left;
    }

    /** not */
    private function negation(int $depth): array
    {
        if ($this->peek() === ['op', 'not']) {
            $this->take();
            $this->enter($depth + 1);

            return ['un', 'not', $this->negation($depth + 1)];
        }

        return $this->comparison($depth);
    }

    /** = != < <= > >= (not chained) */
    private function comparison(int $depth): array
    {
        $left = $this->additive($depth);
        $token = $this->peek();

        if ($token[0] === 'op' && in_array($token[1], ['=', '!=', '<', '<=', '>', '>='], true)) {
            $this->take();
            $left = ['bin', $token[1], $left, $this->additive($depth)];
            $next = $this->peek();

            if ($next[0] === 'op' && in_array($next[1], ['=', '!=', '<', '<=', '>', '>='], true)) {
                throw new FormulaError('chained_comparison');
            }
        }

        return $left;
    }

    private function additive(int $depth): array
    {
        $left = $this->multiplicative($depth);

        while (in_array($this->peek(), [['op', '+'], ['op', '-']], true)) {
            $op = $this->take()[1];
            $left = ['bin', $op, $left, $this->multiplicative($depth)];
        }

        return $left;
    }

    private function multiplicative(int $depth): array
    {
        $left = $this->unary($depth);

        while (in_array($this->peek(), [['op', '*'], ['op', '/']], true)) {
            $op = $this->take()[1];
            $left = ['bin', $op, $left, $this->unary($depth)];
        }

        return $left;
    }

    private function unary(int $depth): array
    {
        if ($this->peek() === ['op', '-']) {
            $this->take();
            $this->enter($depth + 1);

            return ['un', '-', $this->unary($depth + 1)];
        }

        if ($this->peek() === ['op', '+']) {
            $this->take();
            $this->enter($depth + 1);

            return $this->unary($depth + 1);
        }

        return $this->primary($depth);
    }

    private function primary(int $depth): array
    {
        $token = $this->take();

        switch ($token[0]) {
            case 'num':
            case 'str':
            case 'bool':
                return [$token[0], $token[1]];
            case 'null':
                return ['null'];
            case '(':
                $inner = $this->expression($depth + 1);
                $this->expect(')');

                return $inner;
            case 'id':
                if ($this->peek()[0] === '(') {
                    return $this->call(strtolower($token[1]), $depth);
                }

                if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $token[1]) !== 1) {
                    throw new FormulaError('unknown_field', ['field' => $token[1]]);
                }

                return ['ref', $token[1]];
            case 'end':
                throw new FormulaError('incomplete');
            default:
                throw new FormulaError('unexpected', ['token' => (string) $token[1]]);
        }
    }

    private function call(string $name, int $depth): array
    {
        if (! in_array($name, self::FUNCTIONS, true)) {
            throw new FormulaError('unknown_function', ['function' => $name]);
        }

        $this->expect('(');
        $args = [];

        if ($this->peek()[0] !== ')') {
            do {
                if ($args !== []) {
                    $this->take();
                }

                $args[] = $this->expression($depth + 1);
            } while ($this->peek()[0] === ',');
        }

        $this->expect(')');

        [$min, $max] = match ($name) {
            'if' => [3, 3],
            'round' => [1, 2],
            'concat' => [1, 20],
        };

        if (count($args) < $min || count($args) > $max) {
            throw new FormulaError('arguments', ['function' => $name]);
        }

        return ['call', $name, $args];
    }

    // ---- Evaluation -------------------------------------------------------

    /** @param array<string, BigDecimal|string|bool|null> $values */
    private function node(array $node, array $values): BigDecimal|string|bool|null
    {
        return match ($node[0]) {
            'num', 'str', 'bool' => $node[1],
            'null' => null,
            'ref' => array_key_exists($node[1], $values) ? $values[$node[1]] : throw new FormulaError('unknown_field', ['field' => $node[1]]),
            'un' => $this->unaryValue($node[1], $this->node($node[2], $values)),
            'bin' => $this->binary($node[1], $node[2], $node[3], $values),
            'call' => $this->callValue($node[1], $node[2], $values),
        };
    }

    private function unaryValue(string $op, BigDecimal|string|bool|null $value): BigDecimal|bool|null
    {
        if ($op === 'not') {
            return ! self::truthy($value);
        }

        if ($value === null) {
            return null;
        }

        return self::number($value)->negated();
    }

    /** @param array<string, BigDecimal|string|bool|null> $values */
    private function binary(string $op, array $leftNode, array $rightNode, array $values): BigDecimal|string|bool|null
    {
        if ($op === 'and') {
            return self::truthy($this->node($leftNode, $values)) && self::truthy($this->node($rightNode, $values));
        }

        if ($op === 'or') {
            return self::truthy($this->node($leftNode, $values)) || self::truthy($this->node($rightNode, $values));
        }

        $left = $this->node($leftNode, $values);
        $right = $this->node($rightNode, $values);

        if (in_array($op, ['=', '!='], true)) {
            $equal = self::equal($left, $right);

            return $op === '=' ? $equal : ! $equal;
        }

        if ($left === null || $right === null) {
            return null;
        }

        if (in_array($op, ['<', '<=', '>', '>='], true)) {
            $order = self::compare($left, $right);

            return match ($op) {
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
            };
        }

        $a = self::number($left);
        $b = self::number($right);

        return match ($op) {
            '+' => $a->plus($b),
            '-' => $a->minus($b),
            '*' => $a->multipliedBy($b),
            '/' => $b->isZero() ? null : $a->dividedBy($b, self::DIVISION_SCALE, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
        };
    }

    /**
     * @param  list<array<int, mixed>>  $args
     * @param  array<string, BigDecimal|string|bool|null>  $values
     */
    private function callValue(string $name, array $args, array $values): BigDecimal|string|bool|null
    {
        if ($name === 'if') {
            return self::truthy($this->node($args[0], $values)) ? $this->node($args[1], $values) : $this->node($args[2], $values);
        }

        if ($name === 'round') {
            $value = $this->node($args[0], $values);
            $digits = isset($args[1]) ? $this->node($args[1], $values) : BigDecimal::zero();

            if ($value === null) {
                return null;
            }

            $digits = self::number($digits);

            if (! $digits->isEqualTo($digits->toScale(0, RoundingMode::Down)) || $digits->isLessThan(0) || $digits->isGreaterThan(10)) {
                throw new FormulaError('round_digits');
            }

            return self::number($value)->toScale($digits->toInt(), RoundingMode::HalfUp);
        }

        $text = '';

        foreach ($args as $arg) {
            $value = $this->node($arg, $values);
            $text .= $value === null ? '' : self::text($value);

            if (mb_strlen($text) > self::MAX_TEXT) {
                throw new FormulaError('too_long', ['max' => self::MAX_TEXT]);
            }
        }

        return $text;
    }

    private static function number(BigDecimal|string|bool $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        throw new FormulaError('not_a_number');
    }

    private static function truthy(BigDecimal|string|bool|null $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            $value instanceof BigDecimal => ! $value->isZero(),
            default => $value !== '',
        };
    }

    private static function equal(BigDecimal|string|bool|null $left, BigDecimal|string|bool|null $right): bool
    {
        if ($left instanceof BigDecimal && $right instanceof BigDecimal) {
            return $left->isEqualTo($right);
        }

        return $left === $right;
    }

    private static function compare(BigDecimal|string|bool $left, BigDecimal|string|bool $right): int
    {
        if ($left instanceof BigDecimal && $right instanceof BigDecimal) {
            return $left->compareTo($right);
        }

        if (is_string($left) && is_string($right)) {
            return strcmp($left, $right) <=> 0;
        }

        throw new FormulaError('not_comparable');
    }

    private static function text(BigDecimal|string|bool $value): string
    {
        return match (true) {
            $value instanceof BigDecimal => (string) $value->strippedOfTrailingZeros(),
            is_bool($value) => $value ? 'true' : 'false',
            default => $value,
        };
    }
}
