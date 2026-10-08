<?php

namespace Tests\Unit\Core\Workflow;

use App\Core\Currency\Money;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WF-04, WF-05, AUTO-02: the shared condition evaluator: every operator
 * per field type, AND/OR groups, field-to-field comparisons, money in
 * minor units within one currency, dates by day and by instant, missing
 * values, and validation of conditions against a type's fields.
 */
class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    /** @var array<string, FieldDefinition> */
    private array $fields;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ConditionEvaluator;
        $this->fields = [];

        foreach ([
            FieldDefinition::money('total', 'l'),
            FieldDefinition::money('budget', 'l'),
            FieldDefinition::number('quantity', 'l'),
            FieldDefinition::number('limit', 'l'),
            FieldDefinition::enum('category', 'l', ['goods', 'services', 'travel']),
            FieldDefinition::string('note', 'l'),
            FieldDefinition::date('needed_by', 'l'),
            FieldDefinition::date('submitted_at', 'l'),
            FieldDefinition::boolean('urgent', 'l'),
            FieldDefinition::reference('supplier', 'l', 'core.party'),
        ] as $field) {
            $this->fields[$field->name] = $field;
        }
    }

    private function kes(int|string $minor): array
    {
        return ['amount_minor' => $minor, 'currency' => 'KES'];
    }

    /** @return array<string, array{0: array, 1: array, 2: bool}> */
    public static function comparisons(): array
    {
        $kes = fn ($minor) => ['amount_minor' => $minor, 'currency' => 'KES'];

        return [
            'money gt holds' => [['field' => 'total', 'op' => 'gt', 'value' => $kes('25000000')], ['total' => $kes(25000001)], true],
            'money gt fails at equal' => [['field' => 'total', 'op' => 'gt', 'value' => $kes('25000000')], ['total' => $kes('25000000')], false],
            'money gte at equal' => [['field' => 'total', 'op' => 'gte', 'value' => $kes(25000000)], ['total' => $kes('25000000')], true],
            'money lt' => [['field' => 'total', 'op' => 'lt', 'value' => $kes(100)], ['total' => $kes(99)], true],
            'money lte' => [['field' => 'total', 'op' => 'lte', 'value' => $kes(100)], ['total' => $kes(101)], false],
            'money eq' => [['field' => 'total', 'op' => 'eq', 'value' => $kes('500')], ['total' => $kes(500)], true],
            'money ne' => [['field' => 'total', 'op' => 'ne', 'value' => $kes('500')], ['total' => $kes(501)], true],
            'money huge amounts beyond float precision' => [['field' => 'total', 'op' => 'gt', 'value' => $kes('9007199254740993')], ['total' => $kes('9007199254740994')], true],
            'money object value' => [['field' => 'total', 'op' => 'gte', 'value' => $kes(100)], ['total' => Money::ofMinor(100, 'KES')], true],
            'number gt with decimals' => [['field' => 'quantity', 'op' => 'gt', 'value' => '2.5'], ['quantity' => '2.50001'], true],
            'number eq int vs string' => [['field' => 'quantity', 'op' => 'eq', 'value' => 3], ['quantity' => '3.0'], true],
            'number in' => [['field' => 'quantity', 'op' => 'in', 'value' => [1, 2, '3']], ['quantity' => 3], true],
            'number not_in' => [['field' => 'quantity', 'op' => 'not_in', 'value' => [1, 2]], ['quantity' => 3], true],
            'enum eq' => [['field' => 'category', 'op' => 'eq', 'value' => 'goods'], ['category' => 'goods'], true],
            'enum in' => [['field' => 'category', 'op' => 'in', 'value' => ['services', 'travel']], ['category' => 'goods'], false],
            'enum not_in' => [['field' => 'category', 'op' => 'not_in', 'value' => ['services', 'travel']], ['category' => 'goods'], true],
            'string contains is case-insensitive' => [['field' => 'note', 'op' => 'contains', 'value' => 'URGENT'], ['note' => 'Please treat as urgent'], true],
            'string contains fails' => [['field' => 'note', 'op' => 'contains', 'value' => 'laptop'], ['note' => 'chairs'], false],
            'string ne' => [['field' => 'note', 'op' => 'ne', 'value' => 'x'], ['note' => 'y'], true],
            'reference eq' => [['field' => 'supplier', 'op' => 'eq', 'value' => '01a1'], ['supplier' => '01a1'], true],
            'boolean eq true' => [['field' => 'urgent', 'op' => 'eq', 'value' => true], ['urgent' => true], true],
            'boolean eq false' => [['field' => 'urgent', 'op' => 'eq', 'value' => true], ['urgent' => false], false],
            'boolean ne' => [['field' => 'urgent', 'op' => 'ne', 'value' => true], ['urgent' => false], true],
            'date lt by day' => [['field' => 'needed_by', 'op' => 'lt', 'value' => '2026-11-01'], ['needed_by' => '2026-10-31'], true],
            'date eq by day against a date-time' => [['field' => 'needed_by', 'op' => 'eq', 'value' => '2026-11-01'], ['needed_by' => '2026-11-01T15:30:00Z'], true],
            'date-time gt by instant' => [['field' => 'submitted_at', 'op' => 'gt', 'value' => '2026-11-01T08:00:00Z'], ['submitted_at' => '2026-11-01T11:00:01+03:00'], true],
            'date-time not gt across time zones' => [['field' => 'submitted_at', 'op' => 'gt', 'value' => '2026-11-01T08:00:00Z'], ['submitted_at' => '2026-11-01T11:00:00+03:00'], false],
            'empty on null' => [['field' => 'note', 'op' => 'empty'], ['note' => null], true],
            'empty on missing' => [['field' => 'note', 'op' => 'empty'], [], true],
            'empty on blank string' => [['field' => 'note', 'op' => 'empty'], ['note' => ''], true],
            'empty fails on a value' => [['field' => 'note', 'op' => 'empty'], ['note' => 'x'], false],
            'not_empty' => [['field' => 'total', 'op' => 'not_empty'], ['total' => ['amount_minor' => 0, 'currency' => 'KES']], true],
            'missing value never compares' => [['field' => 'total', 'op' => 'gt', 'value' => $kes(0)], [], false],
            'missing value is not equal' => [['field' => 'note', 'op' => 'ne', 'value' => 'x'], [], true],
            'missing value is not in a list' => [['field' => 'category', 'op' => 'not_in', 'value' => ['goods']], [], true],
            'missing value is not in' => [['field' => 'category', 'op' => 'in', 'value' => ['goods']], [], false],
        ];
    }

    #[DataProvider('comparisons')]
    public function test_comparisons(array $condition, array $values, bool $expected): void
    {
        $result = $this->evaluator->evaluate($condition, $values, $this->fields);

        $this->assertSame($expected, $result->passed);
        $this->assertCount(1, $result->checks);
        $this->assertSame($expected ? [] : $result->checks, $result->failures);
    }

    public function test_money_in_another_currency_fails_with_a_reason_and_is_never_converted(): void
    {
        $result = $this->evaluator->evaluate(
            ['field' => 'total', 'op' => 'gt', 'value' => $this->kes(100)],
            ['total' => ['amount_minor' => 1000000, 'currency' => 'USD']],
            $this->fields,
        );

        $this->assertFalse($result->passed);
        $this->assertSame('currency_mismatch', $result->failures[0]->problem);

        // ne across currencies cannot be decided either.
        $this->assertFalse($this->evaluator->evaluate(
            ['field' => 'total', 'op' => 'ne', 'value' => $this->kes(100)],
            ['total' => ['amount_minor' => 100, 'currency' => 'USD']],
            $this->fields,
        )->passed);
    }

    public function test_values_of_the_wrong_shape_fail_as_invalid(): void
    {
        foreach ([
            [['field' => 'total', 'op' => 'gt', 'value' => $this->kes(1)], ['total' => 12.5]],
            [['field' => 'total', 'op' => 'gt', 'value' => $this->kes(1)], ['total' => ['amount_minor' => 1.5, 'currency' => 'KES']]],
            [['field' => 'quantity', 'op' => 'gt', 'value' => 1], ['quantity' => 1.5]],
            [['field' => 'quantity', 'op' => 'gt', 'value' => 1], ['quantity' => 'many']],
            [['field' => 'needed_by', 'op' => 'gt', 'value' => '2026-01-01'], ['needed_by' => 'tomorrow-ish?']],
            [['field' => 'unknown', 'op' => 'eq', 'value' => 'x'], ['unknown' => 'x']],
            [['field' => 'note', 'op' => 'gt', 'value' => 'x'], ['note' => 'y']],
        ] as [$condition, $values]) {
            $result = $this->evaluator->evaluate($condition, $values, $this->fields);
            $this->assertFalse($result->passed, json_encode($condition));
            $this->assertSame('invalid_value', $result->failures[0]->problem, json_encode($condition));
        }
    }

    public function test_a_date_time_compared_with_a_day_is_read_on_the_given_time_zones_day(): void
    {
        $condition = ['field' => 'needed_by', 'op' => 'eq', 'value' => '2026-11-01'];
        $values = ['needed_by' => '2026-10-31T22:30:00Z'];

        $this->assertFalse($this->evaluator->evaluate($condition, $values, $this->fields)->passed, 'still 31 October in UTC');
        $this->assertTrue($this->evaluator->evaluate($condition, $values, $this->fields, 'Africa/Nairobi')->passed, 'already 1 November in Nairobi');
        // Instants are compared as instants whatever the zone.
        $this->assertTrue($this->evaluator->evaluate(['field' => 'submitted_at', 'op' => 'lt', 'value' => '2026-11-01T00:00:00+03:00'], ['submitted_at' => '2026-10-31T20:59:59Z'], $this->fields, 'Africa/Nairobi')->passed);
    }

    public function test_comparing_with_another_field(): void
    {
        $condition = ['field' => 'total', 'op' => 'lte', 'other' => 'budget'];

        $this->assertTrue($this->evaluator->evaluate($condition, ['total' => $this->kes(500), 'budget' => $this->kes(500)], $this->fields)->passed);

        $over = $this->evaluator->evaluate($condition, ['total' => $this->kes(501), 'budget' => $this->kes(500)], $this->fields);
        $this->assertFalse($over->passed);
        $this->assertSame('budget', $over->failures[0]->other);
        $this->assertSame($this->kes('500'), $over->failures[0]->expected);

        $this->assertTrue($this->evaluator->evaluate(['field' => 'quantity', 'op' => 'lt', 'other' => 'limit'], ['quantity' => 2, 'limit' => '10'], $this->fields)->passed);
    }

    public function test_and_or_groups_nest_and_report_the_failures_that_matter(): void
    {
        // Total over KES 250,000 AND (category goods OR urgent).
        $condition = ['all' => [
            ['field' => 'total', 'op' => 'gt', 'value' => $this->kes(25000000)],
            ['any' => [
                ['field' => 'category', 'op' => 'eq', 'value' => 'goods'],
                ['field' => 'urgent', 'op' => 'eq', 'value' => true],
            ]],
        ]];

        $this->assertTrue($this->evaluator->evaluate($condition, ['total' => $this->kes(30000000), 'category' => 'services', 'urgent' => true], $this->fields)->passed);

        $low = $this->evaluator->evaluate($condition, ['total' => $this->kes(100), 'category' => 'goods', 'urgent' => false], $this->fields);
        $this->assertFalse($low->passed);
        $this->assertCount(3, $low->checks, 'every comparison is evaluated (no short circuit)');
        $this->assertSame(['total'], array_map(fn ($c) => $c->field, $low->failures), 'AND fails because of its failing part only');

        $neither = $this->evaluator->evaluate($condition, ['total' => $this->kes(30000000), 'category' => 'travel', 'urgent' => false], $this->fields);
        $this->assertFalse($neither->passed);
        $this->assertSame(['category', 'urgent'], array_map(fn ($c) => $c->field, $neither->failures), 'OR fails because of all its parts');
    }

    public function test_an_empty_condition_always_holds(): void
    {
        $this->assertTrue($this->evaluator->evaluate(null, [], $this->fields)->passed);
        $this->assertTrue($this->evaluator->evaluate([], [], $this->fields)->passed);
        $this->assertSame([], $this->evaluator->validate(null, $this->fields));
    }

    public function test_validation_accepts_well_formed_conditions(): void
    {
        $this->assertSame([], $this->evaluator->validate(['all' => [
            ['field' => 'total', 'op' => 'gt', 'value' => $this->kes('25000000')],
            ['field' => 'category', 'op' => 'in', 'value' => ['goods', 'travel']],
            ['field' => 'total', 'op' => 'lte', 'other' => 'budget'],
            ['any' => [
                ['field' => 'needed_by', 'op' => 'lt', 'value' => '2026-12-31'],
                ['field' => 'submitted_at', 'op' => 'gte', 'value' => '2026-10-01T08:00:00Z'],
                ['field' => 'urgent', 'op' => 'eq', 'value' => true],
                ['field' => 'note', 'op' => 'contains', 'value' => 'laptop'],
                ['field' => 'note', 'op' => 'not_empty'],
                ['field' => 'quantity', 'op' => 'gte', 'value' => '1.5'],
            ]],
        ]], $this->fields));
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function invalidConditions(): array
    {
        $kes = ['amount_minor' => 100, 'currency' => 'KES'];

        return [
            'a list instead of an object' => [[['field' => 'note', 'op' => 'empty']], 'invalid_shape'],
            'an empty group' => [['all' => []], 'empty_group'],
            'a group with extra keys' => [['all' => [['field' => 'note', 'op' => 'empty']], 'any' => []], 'invalid_shape'],
            'an unknown field' => [['field' => 'colour', 'op' => 'eq', 'value' => 'red'], 'unknown_field'],
            'an unknown operator' => [['field' => 'note', 'op' => 'like', 'value' => 'x'], 'unknown_operator'],
            'gt on a string' => [['field' => 'note', 'op' => 'gt', 'value' => 'x'], 'operator_not_allowed'],
            'contains on money' => [['field' => 'total', 'op' => 'contains', 'value' => 'x'], 'operator_not_allowed'],
            'in on money' => [['field' => 'total', 'op' => 'in', 'value' => [$kes]], 'operator_not_allowed'],
            'a float number' => [['field' => 'quantity', 'op' => 'gt', 'value' => 1.5], 'invalid_value'],
            'money as a plain number' => [['field' => 'total', 'op' => 'gt', 'value' => 250000], 'invalid_value'],
            'money with a float amount' => [['field' => 'total', 'op' => 'gt', 'value' => ['amount_minor' => 1.5, 'currency' => 'KES']], 'invalid_value'],
            'money with a bad currency' => [['field' => 'total', 'op' => 'gt', 'value' => ['amount_minor' => 1, 'currency' => 'kes']], 'invalid_value'],
            'an enum value not offered' => [['field' => 'category', 'op' => 'eq', 'value' => 'food'], 'invalid_value'],
            'a date that is not a date' => [['field' => 'needed_by', 'op' => 'gt', 'value' => 'next week'], 'invalid_value'],
            'a boolean as a string' => [['field' => 'urgent', 'op' => 'eq', 'value' => 'true'], 'invalid_value'],
            'no value' => [['field' => 'note', 'op' => 'eq'], 'invalid_value'],
            'both value and other' => [['field' => 'total', 'op' => 'gt', 'value' => $kes, 'other' => 'budget'], 'invalid_value'],
            'a value on empty' => [['field' => 'note', 'op' => 'empty', 'value' => 'x'], 'invalid_value'],
            'an empty in list' => [['field' => 'category', 'op' => 'in', 'value' => []], 'invalid_value'],
            'an other field of another type' => [['field' => 'total', 'op' => 'gt', 'other' => 'quantity'], 'other_field_mismatch'],
            'an unknown other field' => [['field' => 'total', 'op' => 'gt', 'other' => 'ceiling'], 'unknown_field'],
            'an extra key' => [['field' => 'note', 'op' => 'empty', 'not' => true], 'invalid_shape'],
            'too deep' => [['all' => [['all' => [['all' => [['all' => [['all' => [['field' => 'note', 'op' => 'empty']]]]]]]]]]], 'too_deep'],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function test_validation_names_each_problem(mixed $condition, string $code): void
    {
        $problems = $this->evaluator->validate($condition, $this->fields);

        $this->assertContains($code, array_column($problems, 'code'), json_encode($problems));
    }

    public function test_validation_points_at_the_nested_rule(): void
    {
        $problems = $this->evaluator->validate(['all' => [
            ['field' => 'note', 'op' => 'empty'],
            ['any' => [['field' => 'note', 'op' => 'empty'], ['field' => 'colour', 'op' => 'eq', 'value' => 'red']]],
        ]], $this->fields);

        $this->assertSame([['code' => 'unknown_field', 'path' => 'all.1.any.1', 'params' => ['field' => 'colour']]], $problems);
    }

    public function test_too_many_comparisons_are_refused(): void
    {
        $many = array_fill(0, ConditionEvaluator::MAX_COMPARISONS + 1, ['field' => 'note', 'op' => 'empty']);

        $this->assertContains('too_many', array_column($this->evaluator->validate(['any' => $many], $this->fields), 'code'));
    }

    public function test_fields_of_lists_every_field_read(): void
    {
        $this->assertSame(['total', 'budget', 'category'], $this->evaluator->fieldsOf(['all' => [
            ['field' => 'total', 'op' => 'lte', 'other' => 'budget'],
            ['any' => [['field' => 'category', 'op' => 'eq', 'value' => 'goods']]],
        ]]));
    }
}
