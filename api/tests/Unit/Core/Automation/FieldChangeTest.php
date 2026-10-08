<?php

namespace Tests\Unit\Core\Automation;

use App\Core\Automation\Triggers\FieldChange;
use App\Core\Currency\Money;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUTO-01 "threshold crossed" and "field changed": crossings between the
 * old and new value (down: from at or above to below; up: from at or
 * below to above), numbers as decimals (never floats), money in minor
 * units within the threshold's currency only, missing values never
 * crossing; changes compared by type (12.5 = 12.50; another currency is a
 * change; dates by day in a time zone).
 */
class FieldChangeTest extends TestCase
{
    private FieldChange $change;

    protected function setUp(): void
    {
        parent::setUp();
        $this->change = new FieldChange(new ConditionEvaluator);
    }

    private static function kes(int $minor): array
    {
        return ['amount_minor' => (string) $minor, 'currency' => 'KES'];
    }

    /** @return array<string, array{string, mixed, string, mixed, mixed, bool}> type, threshold, direction, old, new, crossed */
    public static function crossings(): array
    {
        return [
            'down: above to below' => ['number', '10', 'down', '12', '9', true],
            'down: at the level to below' => ['number', '10', 'down', '10', '9.99', true],
            'down: above to the level' => ['number', '10', 'down', '12', '10', false],
            'down: below to further below' => ['number', '10', 'down', '9', '3', false],
            'down: rising' => ['number', '10', 'down', '9', '12', false],
            'down: decimals' => ['number', '0.5', 'down', '0.50', '0.49', true],
            'down: negative' => ['number', '0', 'down', '5', '-1', true],
            'down: ints' => ['number', 10, 'down', 11, 9, true],
            'up: below to above' => ['number', '10', 'up', '9', '11', true],
            'up: at the level to above' => ['number', '10', 'up', '10', '10.01', true],
            'up: staying above' => ['number', '10', 'up', '11', '12', false],
            'missing old value' => ['number', '10', 'down', null, '9', false],
            'missing new value' => ['number', '10', 'down', '12', null, false],
            'money down' => ['money', self::kes(100000), 'down', self::kes(150000), self::kes(99999), true],
            'money at the level' => ['money', self::kes(100000), 'down', self::kes(150000), self::kes(100000), false],
            'money as Money objects' => ['money', self::kes(100000), 'down', Money::ofMinor('150000', 'KES'), Money::ofMinor('50000', 'KES'), true],
            'money in another currency never crosses' => ['money', self::kes(100000), 'down', self::kes(150000), ['amount_minor' => '10', 'currency' => 'USD'], false],
            'money up' => ['money', self::kes(100000), 'up', self::kes(100000), self::kes(100001), true],
        ];
    }

    #[DataProvider('crossings')]
    public function test_threshold_crossings(string $type, mixed $threshold, string $direction, mixed $old, mixed $new, bool $crossed): void
    {
        $field = new FieldDefinition('level', $type, 'label');

        $this->assertSame($crossed, $this->change->crossed($field, $threshold, $direction, $old, $new));
    }

    public function test_changes_are_compared_by_field_type(): void
    {
        $number = FieldDefinition::number('n', 'label');
        $money = FieldDefinition::money('m', 'label');
        $date = FieldDefinition::date('d', 'label');
        $text = FieldDefinition::string('s', 'label');

        $this->assertFalse($this->change->changed($number, '12.5', '12.50'));
        $this->assertTrue($this->change->changed($number, '12.5', '12.51'));
        $this->assertTrue($this->change->changed($number, null, '0'));
        $this->assertFalse($this->change->changed($number, null, ''));
        $this->assertFalse($this->change->changed($money, self::kes(100), Money::ofMinor('100', 'KES')));
        $this->assertTrue($this->change->changed($money, self::kes(100), ['amount_minor' => '100', 'currency' => 'USD']));
        $this->assertTrue($this->change->changed($text, 'a', 'A'));
        // The same instant on the same local day.
        $this->assertFalse($this->change->changed($date, '2026-10-08T21:30:00Z', '2026-10-09', 'Africa/Nairobi'));
        $this->assertTrue($this->change->changed($date, '2026-10-08T20:30:00Z', '2026-10-09', 'Africa/Nairobi'));
    }
}
