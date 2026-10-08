<?php

namespace App\Core\MasterData\Items\Http\Requests;

/**
 * MD-02: add an image to an item (`core.item.edit`): `image`, a JPEG, PNG
 * or WebP file of at most 2 MB whose content really is an image (its
 * dimensions are read), at most 8000 pixels a side.
 */
class StoreItemImageRequest extends ItemRequest
{
    public const MAX_KILOBYTES = 2048;

    protected string $ability = 'update';

    public function rules(): array
    {
        return [
            'image' => [
                'required', 'file', 'max:'.self::MAX_KILOBYTES,
                'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:min_width=1,min_height=1,max_width=8000,max_height=8000',
            ],
        ];
    }

    public function attributes(): array
    {
        return ['image' => __('core.item.attributes.image')];
    }

    public function messages(): array
    {
        return [
            'image.max' => __('core.item.image_too_large', ['max' => '2 MB']),
            'image.mimetypes' => __('core.item.image_type'),
            'image.dimensions' => __('core.item.image_unreadable'),
        ];
    }
}
