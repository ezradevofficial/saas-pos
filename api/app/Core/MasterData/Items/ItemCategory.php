<?php

namespace App\Core\MasterData\Items;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * MD-02: a category of items, in a tree (parent_id). Shared across the
 * group when company_id is null, else one company's, following the items
 * sharing mode (TEN-08); a parent is in the same scope. `colour` is a
 * design token name for POS tiles (LAY-05), never a colour value. Archived,
 * never deleted (TEN-06); audited as `core.item_category.*` (MD-07).
 */
#[UsePolicy(ItemCategoryPolicy::class)]
class ItemCategory extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['company_id', 'parent_id', 'name_en', 'name_fr', 'colour'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    /**
     * The ids of $ids and every category beneath them (archived included).
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?::uuid'));

        return array_map(fn (object $row) => $row->id, DB::connection((new self)->getConnectionName())->select(
            "with recursive tree(id) as (
                select id from item_categories where id in ({$placeholders})
                union
                select c.id from item_categories c join tree t on c.parent_id = t.id
            ) select id::text from tree",
            $ids,
        ));
    }

    /** True when $candidate is this category or beneath it (a parent there would make a cycle). */
    public function isAncestorOf(string $candidate): bool
    {
        return in_array($candidate, self::withDescendants([$this->id]), true);
    }
}
