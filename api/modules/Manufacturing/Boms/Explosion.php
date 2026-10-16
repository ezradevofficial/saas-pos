<?php

namespace Modules\Manufacturing\Boms;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Errors;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-05: a BOM version exploded for a demand of D base units of its
 * output item, at a moment:
 *
 *     need = qty_base × D ÷ output_qty_base × 100 ÷ yield × (1 + wastage ÷ 100)
 *
 * per component line. An exploded (phantom) component's need becomes the
 * demand of its own version in force at the same moment (the parent's kind
 * first), down to items taken from stock. Every step keeps 18 places; each
 * leaf item is summed over the tree and rounded once to 6 places (half
 * up), as is its expected loss (need − net). A BOM met again on its own
 * path is a cycle (`bom_cycle`); more than 10 levels is refused
 * (`bom_too_deep`); an exploded component with no version in force is
 * refused (`no_bom_in_force`). By-products are the top level's, for the
 * same demand.
 */
class Explosion
{
    public const MAX_DEPTH = 10;

    public function __construct(private readonly Versions $versions) {}

    /**
     * @param  array<string, BomVersion>  $overrides  bom id => the version to use for it (a draft being published)
     * @return array{tree: list<array<string, mixed>>, leaves: list<array{item_id: string, qty_base: BigDecimal, loss_base: BigDecimal}>, by_products: list<array{line_id: string, item_id: string, qty_base: BigDecimal, cost_share_percent: string}>}
     */
    public function explode(BomVersion $version, BigDecimal $demand, CarbonImmutable $at, array $overrides = []): array
    {
        $bom = $version->relationLoaded('bom') ? $version->bom : Bom::query()->findOrFail($version->bom_id);
        $leaves = [];
        $tree = $this->level($bom, $version, $demand, $at, $overrides, [$bom->id], 1, $leaves);
        $batches = $demand->dividedBy((string) $version->output_qty_base, Qty::WORK, RoundingMode::HalfUp);
        $byProducts = [];

        foreach ($this->lines($version) as $line) {
            if ($line->kind === BomLine::BY_PRODUCT) {
                $byProducts[] = [
                    'line_id' => $line->id, 'item_id' => $line->item_id,
                    'qty_base' => Qty::of(BigDecimal::of((string) $line->qty_base)->multipliedBy($batches)),
                    'cost_share_percent' => (string) $line->cost_share_percent,
                ];
            }
        }

        return [
            'tree' => $tree,
            'leaves' => array_values(array_map(fn (array $leaf) => [
                'item_id' => $leaf['item_id'],
                'qty_base' => Qty::of($leaf['need']),
                'loss_base' => Qty::of($leaf['need']->minus($leaf['net'])),
            ], $leaves)),
            'by_products' => $byProducts,
        ];
    }

    /**
     * @param  list<string>  $path  the BOMs on the way here
     * @param  array<string, array{item_id: string, need: BigDecimal, net: BigDecimal}>  $leaves
     * @return list<array<string, mixed>>
     */
    private function level(Bom $bom, BomVersion $version, BigDecimal $demand, CarbonImmutable $at, array $overrides, array $path, int $depth, array &$leaves): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw Errors::make(422, 'bom_too_deep', ['max' => self::MAX_DEPTH]);
        }

        $nodes = [];

        foreach ($this->lines($version) as $line) {
            if ($line->kind !== BomLine::COMPONENT) {
                continue;
            }

            $net = Qty::ratio((string) $line->qty_base, $demand, (string) $version->output_qty_base);
            $need = $net->multipliedBy(100)->dividedBy((string) $line->yield_percent, Qty::WORK, RoundingMode::HalfUp)
                ->multipliedBy(BigDecimal::of((string) $line->wastage_percent)->dividedBy(100, Qty::WORK)->plus(1))
                ->toScale(Qty::WORK, RoundingMode::HalfUp);
            $node = [
                'line_id' => $line->id, 'item_id' => $line->item_id, 'explode' => (bool) $line->explode,
                'qty_base' => Qty::show(Qty::of($need)), 'net_base' => Qty::show(Qty::of($net)),
                'yield_percent' => Qty::show((string) $line->yield_percent), 'wastage_percent' => Qty::show((string) $line->wastage_percent),
                'version_id' => null, 'children' => [],
            ];

            if ($line->explode) {
                $child = $this->childVersion($bom, $line, $at, $overrides);

                if ($child === null) {
                    throw Errors::make(422, 'no_bom_in_force', ['item' => $line->item?->name ?? $line->item_id]);
                }

                if (in_array($child->bom_id, $path, true)) {
                    throw Errors::make(422, 'bom_cycle', ['item' => $line->item?->name ?? $line->item_id]);
                }

                $node['version_id'] = $child->id;
                $node['children'] = $this->level($child->bom, $child, $need, $at, $overrides, [...$path, $child->bom_id], $depth + 1, $leaves);
            } else {
                $leaves[$line->item_id] ??= ['item_id' => $line->item_id, 'need' => BigDecimal::zero(), 'net' => BigDecimal::zero()];
                $leaves[$line->item_id]['need'] = $leaves[$line->item_id]['need']->plus($need);
                $leaves[$line->item_id]['net'] = $leaves[$line->item_id]['net']->plus($net);
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    private function childVersion(Bom $parent, BomLine $line, CarbonImmutable $at, array $overrides): ?BomVersion
    {
        foreach ($overrides as $bomId => $version) {
            $bom = $version->bom;

            if ($bom->company_id === $parent->company_id && $bom->item_id === $line->item_id && $bom->archived_at === null) {
                return $version;
            }
        }

        $version = $this->versions->forComponent($parent->company_id, $line->item_id, $parent->kind, $at);
        $version?->loadMissing('bom');

        return $version;
    }

    /** @return iterable<BomLine> */
    private function lines(BomVersion $version): iterable
    {
        if (! $version->relationLoaded('lines')) {
            $version->load('lines.item');
        }

        return $version->lines;
    }
}
