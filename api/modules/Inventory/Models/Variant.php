<?php

namespace Modules\Inventory\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * INV-02: an item sold as a variant of a parent item, with its attribute
 * values (`{"size": "M", "colour": "Red"}`). Audited as
 * `inventory.variant.*` (INV-24).
 *
 * @property string $item_id
 * @property string $parent_item_id
 * @property array<string, string> $attributes
 */
class Variant extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'inventory_variants';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'variant';

    protected $fillable = ['item_id', 'parent_item_id', 'attributes'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_item_id');
    }
}
