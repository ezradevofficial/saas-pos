<?php

namespace App\Core\MasterData\Items\Http\Requests;

/** TEN-06: archive or restore an item category (`core.item_category.archive`). No body. */
class ItemCategoryActionRequest extends ItemCategoryRequest
{
    protected string $ability = 'archive';
}
