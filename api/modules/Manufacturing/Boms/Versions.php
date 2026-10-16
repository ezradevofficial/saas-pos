<?php

namespace Modules\Manufacturing\Boms;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;

/**
 * MFG-03: the version of an item's BOM in force at a moment: the
 * published version of the company's active BOM of that kind with the
 * latest `effective_from` at or before it.
 */
class Versions
{
    /** @var array<string, ?BomVersion> */
    private array $memo = [];

    public function inForce(string $companyId, string $itemId, string $kind, CarbonImmutable $at): ?BomVersion
    {
        $key = implode('|', [$companyId, $itemId, $kind, $at->format('Y-m-d H:i:s.u')]);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        // Review fix 2: every BOM of the item and kind that was active at the moment (not archived
        // then), so an offline sale uploaded after a recipe was archived or replaced uses what was
        // in force when it was sold.
        $stamp = $at->format('Y-m-d H:i:s.uP');
        $boms = Bom::query()->select('id')->where('company_id', $companyId)->where('item_id', $itemId)->where('kind', $kind)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhere('archived_at', '>', $stamp));

        return $this->memo[$key] = BomVersion::query()->whereIn('bom_id', $boms)->where('status', BomVersion::PUBLISHED)
            ->where('effective_from', '<=', $stamp)->orderByDesc('effective_from')->orderByDesc('version_no')->with('bom')->first();
    }

    /** The version of $bomId in force at $at. */
    public function ofBom(string $bomId, CarbonImmutable $at): ?BomVersion
    {
        // Microseconds and offset kept: a version published a moment ago is in force now.
        return BomVersion::query()->where('bom_id', $bomId)->where('status', BomVersion::PUBLISHED)
            ->where('effective_from', '<=', $at->format('Y-m-d H:i:s.uP'))->orderByDesc('effective_from')->orderByDesc('version_no')->first();
    }

    /**
     * The version an exploded component takes: its BOM of $preferred kind in
     * force at $at, else of the other kind (MFG-05).
     */
    public function forComponent(string $companyId, string $itemId, string $preferred, CarbonImmutable $at): ?BomVersion
    {
        $other = $preferred === Bom::RECIPE ? Bom::PRODUCTION : Bom::RECIPE;

        return $this->inForce($companyId, $itemId, $preferred, $at) ?? $this->inForce($companyId, $itemId, $other, $at);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
