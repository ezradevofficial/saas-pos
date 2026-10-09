<?php

namespace Tests\Unit\Core\Configuration;

use App\Core\Configuration\CatalogueMerge;
use PHPUnit\Framework\TestCase;

/**
 * LAY-07: a stored layout merged with the platform's current catalogue
 * never breaks: unknown entries are skipped, the layout's order and
 * settings are kept, new entries appear in a default position or hidden.
 */
class CatalogueMergeTest extends TestCase
{
    private const CATALOGUE = [
        ['id' => 'name', 'label' => 'Name', 'width' => 4],
        ['id' => 'code', 'label' => 'Code', 'width' => 2],
        ['id' => 'price', 'label' => 'Price', 'width' => 2],
    ];

    public function test_the_layouts_order_and_settings_win_over_the_catalogue_defaults(): void
    {
        $merged = CatalogueMerge::entries(
            [['id' => 'price', 'width' => 3], ['id' => 'name'], ['id' => 'code', 'label' => 'SKU']],
            self::CATALOGUE,
        );

        $this->assertSame([
            ['id' => 'price', 'label' => 'Price', 'width' => 3],
            ['id' => 'name', 'label' => 'Name', 'width' => 4],
            ['id' => 'code', 'label' => 'SKU', 'width' => 2],
        ], $merged);
    }

    public function test_entries_that_no_longer_exist_and_repeats_are_skipped(): void
    {
        $merged = CatalogueMerge::entries(
            [['id' => 'retired'], ['id' => 'name'], ['label' => 'no id'], 'not an entry', ['id' => 'name', 'width' => 9], ['id' => 'code'], ['id' => 'price']],
            self::CATALOGUE,
        );

        $this->assertSame(['name', 'code', 'price'], array_column($merged, 'id'));
        $this->assertSame(4, $merged[0]['width'], 'the first mention is the one kept');
    }

    public function test_new_catalogue_entries_are_appended_by_default(): void
    {
        $merged = CatalogueMerge::entries([['id' => 'price'], ['id' => 'name']], self::CATALOGUE);

        $this->assertSame(['price', 'name', 'code'], array_column($merged, 'id'));
        $this->assertArrayNotHasKey('hidden', $merged[2]);
    }

    public function test_new_entries_go_after_their_anchor_in_catalogue_order(): void
    {
        $catalogue = [
            ...self::CATALOGUE,
            ['id' => 'barcode', 'label' => 'Barcode', 'after' => 'code'],
            ['id' => 'brand', 'label' => 'Brand', 'after' => 'code'],
            ['id' => 'notes', 'label' => 'Notes', 'after' => 'gone'],
        ];

        $merged = CatalogueMerge::entries([['id' => 'code'], ['id' => 'name'], ['id' => 'price']], $catalogue);

        $this->assertSame(['code', 'barcode', 'brand', 'name', 'price', 'notes'], array_column($merged, 'id'));
        $this->assertArrayNotHasKey('after', $merged[1], 'catalogue hints never reach the layout');
    }

    public function test_the_hidden_rule_adds_new_entries_hidden_and_an_entry_may_choose_its_own_rule(): void
    {
        $catalogue = [...self::CATALOGUE, ['id' => 'margin', 'when_new' => CatalogueMerge::APPEND]];

        $merged = CatalogueMerge::entries([['id' => 'name']], $catalogue, CatalogueMerge::HIDDEN);

        $this->assertSame(['name', 'code', 'price', 'margin'], array_column($merged, 'id'));
        $this->assertSame([null, true, true, null], array_map(fn ($e) => $e['hidden'] ?? null, $merged));
        $this->assertArrayNotHasKey('when_new', $merged[3]);

        // A layout that hid an entry itself keeps it as it said.
        $kept = CatalogueMerge::entries([['id' => 'name', 'hidden' => false], ['id' => 'code', 'hidden' => true], ['id' => 'price']], self::CATALOGUE, CatalogueMerge::HIDDEN);
        $this->assertSame([false, true, null], array_map(fn ($e) => $e['hidden'] ?? null, $kept));
    }

    public function test_an_empty_layout_gets_the_whole_catalogue(): void
    {
        $this->assertSame(['name', 'code', 'price'], array_column(CatalogueMerge::entries([], self::CATALOGUE), 'id'));
        $this->assertSame([], CatalogueMerge::entries([['id' => 'name']], []));
    }

    public function test_grouped_layouts_keep_their_groups_and_place_new_entries_by_hint(): void
    {
        $catalogue = [
            ['id' => 'name'], ['id' => 'code'], ['id' => 'price'],
            ['id' => 'cost', 'group' => 'pricing'],
            ['id' => 'barcode', 'after' => 'code'],
            ['id' => 'notes'],
        ];
        $layout = [
            ['id' => 'general', 'label' => 'General', 'fields' => [['id' => 'name'], ['id' => 'retired'], ['id' => 'code']]],
            ['id' => 'pricing', 'label' => 'Pricing', 'fields' => [['id' => 'price', 'width' => 6], ['id' => 'name']]],
        ];

        $merged = CatalogueMerge::grouped($layout, $catalogue);

        $this->assertSame(['general', 'pricing'], array_column($merged, 'id'));
        $this->assertSame('General', $merged[0]['label']);
        // Unknown skipped; a field placed twice stays where it was first; barcode after its anchor; notes in the first group.
        $this->assertSame(['name', 'code', 'barcode', 'notes'], array_column($merged[0]['fields'], 'id'));
        $this->assertSame(['price', 'cost'], array_column($merged[1]['fields'], 'id'));
        $this->assertSame(6, $merged[1]['fields'][0]['width']);
    }

    public function test_grouped_layouts_hide_new_entries_or_create_a_group_when_there_is_none(): void
    {
        $hidden = CatalogueMerge::grouped([['id' => 'main', 'fields' => [['id' => 'name']]]], self::CATALOGUE, 'fields', CatalogueMerge::HIDDEN);
        $this->assertSame([null, true, true], array_map(fn ($e) => $e['hidden'] ?? null, $hidden[0]['fields']));

        $created = CatalogueMerge::grouped([], self::CATALOGUE, 'items', CatalogueMerge::APPEND, ['id' => 'more', 'label' => 'More']);
        $this->assertSame([['id' => 'more', 'label' => 'More', 'items' => [
            ['id' => 'name', 'label' => 'Name', 'width' => 4],
            ['id' => 'code', 'label' => 'Code', 'width' => 2],
            ['id' => 'price', 'label' => 'Price', 'width' => 2],
        ]]], $created);
    }

    public function test_catalogue_owned_attributes_always_come_from_the_catalogue(): void
    {
        $catalogue = [
            ['id' => 'total', 'label' => 'Total', 'width' => 2, 'locked' => true, 'permission' => 'pos.sale.view_total', 'required' => true, 'module' => 'pos'],
        ];
        $stored = [['id' => 'total', 'width' => 4, 'label' => 'Grand total', 'hidden' => true, 'locked' => false, 'permission' => null, 'required' => false, 'module' => 'core', 'onclick' => 'x']];

        $merged = CatalogueMerge::entries($stored, $catalogue);

        // Layout keys are the layout's; locked, permission, required and module the catalogue's; unknown keys dropped.
        $this->assertSame(
            ['id' => 'total', 'label' => 'Grand total', 'width' => 4, 'locked' => true, 'permission' => 'pos.sale.view_total', 'required' => true, 'module' => 'pos', 'hidden' => true],
            $merged[0],
        );

        // A kind declares its own layout keys; the same rule holds in groups.
        $grouped = CatalogueMerge::grouped([['id' => 'main', 'fields' => [['id' => 'total', 'span' => 2, 'locked' => false]]]], $catalogue, layoutKeys: [...CatalogueMerge::LAYOUT_KEYS, 'span']);
        $this->assertSame(2, $grouped[0]['fields'][0]['span']);
        $this->assertTrue($grouped[0]['fields'][0]['locked']);
    }

    public function test_malformed_groups_and_items_are_skipped(): void
    {
        $merged = CatalogueMerge::grouped(
            ['junk', 5, null, ['id' => 'main', 'fields' => 'not a list'], ['id' => 'more', 'fields' => [1, 'x', ['id' => ['nested']], ['id' => 'code']]]],
            self::CATALOGUE,
        );

        $this->assertSame(['main', 'more'], array_column($merged, 'id'));
        $this->assertSame(['name', 'price'], array_column($merged[0]['fields'], 'id'));
        $this->assertSame(['code'], array_column($merged[1]['fields'], 'id'));
    }
}
