<?php

namespace App\Core\MasterData\Items\Http\Requests;

/** TEN-06: archive or restore an item (`core.item.archive` where it is reached). No body. */
class ItemActionRequest extends ItemRequest
{
    protected string $ability = 'archive';
}
