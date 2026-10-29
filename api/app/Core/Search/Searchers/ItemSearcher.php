<?php

namespace App\Core\Search\Searchers;

use App\Core\MasterData\Items\Http\Resources\ItemResource;
use App\Core\MasterData\Items\Item;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * LAY-01, MD-02: items by name (trigram), code (prefix, on items_code_prefix)
 * or barcode (prefix). Shared items are found anywhere, a company's from its
 * company or below (RBAC-04); a field the user's rules hide is neither
 * matched nor shown (RBAC-05).
 */
class ItemSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'item';
    }

    public function module(): string
    {
        return 'core';
    }

    public function permission(): string
    {
        return 'core.item.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $hides = fn (string $field) => $context->hides(ItemResource::FIELD_RULES, $field);

        if ($hides('name') && $hides('code')) {
            return [];
        }

        $query = Item::query()->whereNull('archived_at');
        $companies = $context->visible->companiesTouched();

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        $query->where(function (Builder $q) use ($term, $hides) {
            $q->whereRaw('false');

            if (! $hides('name')) {
                $q->orWhereRaw('name ~* ?', [$term->regex()]);
            }

            if (! $hides('code')) {
                $q->orWhereRaw('lower(code::text) like lower(?)', [$term->prefix()]);
            }

            if (! $hides('barcodes')) {
                $q->orWhereRaw('exists (select 1 from item_barcodes b where b.item_id = items.id and b.barcode like ?)', [$term->prefix()]);
            }
        });

        return $query
            ->orderByRaw('(lower(code::text) like lower(?)) desc, lower(name)', [$term->prefix()])
            ->limit($term->limit)
            ->get(['id', 'code', 'name'])
            ->map(fn (Item $item) => new RecordHit(
                $item->id,
                $hides('name') ? (string) $item->code : $item->name,
                $hides('code') || $hides('name') ? null : (string) $item->code,
                "/catalogue/items/{$item->id}",
            ))
            ->all();
    }
}
