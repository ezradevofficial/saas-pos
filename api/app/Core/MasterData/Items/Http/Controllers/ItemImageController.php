<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\MasterData\Items\Http\Requests\ItemImageRequest;
use App\Core\MasterData\Items\Http\Requests\ReorderItemImagesRequest;
use App\Core\MasterData\Items\Http\Requests\StoreItemImageRequest;
use App\Core\MasterData\Items\Http\Resources\ItemResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Items\ItemImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * MD-02: item images (at most Item::MAX_IMAGES). Each answer is the item,
 * its images with fresh temporary URLs. Images are files: a delete removes
 * the file and the row, audited on the item.
 */
class ItemImageController
{
    private const RELATIONS = ['uoms.uom:id,code', 'barcodes', 'images'];

    public function __construct(private readonly ItemImages $images) {}

    public function store(StoreItemImageRequest $request, Item $item): JsonResponse
    {
        $this->images->add($item, $request->file('image'));

        return ItemResource::make($item->refresh()->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function reorder(ReorderItemImagesRequest $request, Item $item): ItemResource
    {
        $this->images->reorder($item, $request->validated('image_ids'));

        return ItemResource::make($item->refresh()->load(self::RELATIONS));
    }

    public function destroy(ItemImageRequest $request, ItemImage $itemImage): Response
    {
        $this->images->delete($itemImage);

        return response()->noContent();
    }
}
