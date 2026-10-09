<?php

namespace Tests\Unit\Core\Configuration;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\PayloadSchema;
use InvalidArgumentException;
use Tests\TestCase;

/** LAY-06: the schema-like validator kinds use to refuse publishing a bad payload. */
class PayloadSchemaTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['columns'],
        'additional' => false,
        'properties' => [
            'title' => ['type' => 'string', 'min' => 1, 'max' => 10, 'nullable' => true],
            'density' => ['type' => 'string', 'enum' => ['compact', 'comfortable']],
            'columns' => ['type' => 'array', 'min' => 1, 'max' => 3, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '/^[a-z_]+$/'],
                    'width' => ['type' => 'integer', 'min' => 1, 'max' => 12],
                    'visible' => ['type' => 'boolean'],
                ],
            ]],
        ],
    ];

    public function test_a_valid_payload_has_no_problems(): void
    {
        $this->assertSame([], PayloadSchema::check([
            'title' => null,
            'density' => 'compact',
            'columns' => [['id' => 'name', 'width' => 4, 'visible' => true], ['id' => 'code']],
        ], self::SCHEMA));
    }

    public function test_problems_name_the_path_the_rule_and_say_what_to_do(): void
    {
        $problems = PayloadSchema::check([
            'title' => 'A title too long',
            'density' => 'loud',
            'colour' => 'red',
            'columns' => [['id' => 'Name!', 'width' => 13], ['width' => '4'], ['id' => 'name'], ['id' => 'name']],
        ], self::SCHEMA);

        $this->assertSame([
            'title' => 'max_length',
            'density' => 'enum',
            'colour' => 'unknown',
            'columns' => 'max_items',
        ], array_column($problems, 'code', 'path'));
        $this->assertSame('title can have at most 10 characters.', $problems[0]['message']);

        $items = PayloadSchema::check(['columns' => [['id' => 'Name!', 'width' => 13], ['width' => '4'], ['id' => 'name'], ['id' => 'name']]], [
            ...self::SCHEMA,
            'properties' => [...self::SCHEMA['properties'], 'columns' => [...self::SCHEMA['properties']['columns'], 'max' => 10]],
        ]);

        $this->assertSame([
            ['columns.0.id', 'pattern'],
            ['columns.0.width', 'max'],
            ['columns.1.id', 'required'],
            ['columns.1.width', 'type'],
            ['columns.3.id', 'duplicate'],
        ], array_map(fn ($p) => [$p['path'], $p['code']], $items));
    }

    public function test_the_root_and_missing_values(): void
    {
        $this->assertSame([['', 'type']], array_map(fn ($p) => [$p['path'], $p['code']], PayloadSchema::check(['a', 'b'], self::SCHEMA)));
        $this->assertSame([['columns', 'required']], array_map(fn ($p) => [$p['path'], $p['code']], PayloadSchema::check([], self::SCHEMA)));
        $this->assertSame([['columns', 'min_items']], array_map(fn ($p) => [$p['path'], $p['code']], PayloadSchema::check(['columns' => []], self::SCHEMA)));
        $this->assertSame([['density', 'not_null']], array_map(fn ($p) => [$p['path'], $p['code']], PayloadSchema::check(['columns' => [['id' => 'a']], 'density' => null], self::SCHEMA)));
    }

    public function test_messages_are_translated(): void
    {
        app()->setLocale('fr');

        $this->assertSame('columns est manquant.', PayloadSchema::check([], self::SCHEMA)[0]['message']);
    }

    public function test_a_kind_checks_with_its_schema_or_closure_and_validates_its_definition(): void
    {
        $kind = new ConfigKind('list_view', schema: self::SCHEMA, keys: ['items', 'parties']);
        $this->assertSame('required', $kind->problems([])[0]['code']);
        $this->assertTrue($kind->acceptsKey('items'));
        $this->assertFalse($kind->acceptsKey('orders'));
        $this->assertSame('core.config.publish', $kind->permission('publish'));

        $custom = new ConfigKind('theme', schema: fn (array $p) => isset($p['primary']) ? [] : [PayloadSchema::problem('primary', 'required')], permissions: ['publish' => 'core.theme.publish']);
        $this->assertSame('primary', $custom->problems([])[0]['path']);
        $this->assertSame(['view' => 'core.config.view', 'edit' => 'core.config.edit', 'publish' => 'core.theme.publish'], $custom->permissions);
        $this->assertTrue($custom->acceptsKey('default'));
        $this->assertFalse($custom->acceptsKey('Bad Key'));

        $this->expectException(InvalidArgumentException::class);
        new ConfigKind('theme', scopes: ['planet']);
    }
}
