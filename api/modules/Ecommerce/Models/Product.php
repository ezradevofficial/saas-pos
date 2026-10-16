<?php

namespace Modules\Ecommerce\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Archivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ECOM-02: a core item listed in a store. */
class Product extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_products';

    protected string $auditResource = 'product';

    protected function casts(): array
    {
        return ['published' => 'boolean', 'featured' => 'boolean', 'track_stock' => 'boolean', 'in_stock' => 'boolean', 'position' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
