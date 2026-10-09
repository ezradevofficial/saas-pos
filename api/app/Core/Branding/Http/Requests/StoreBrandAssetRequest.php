<?php

namespace App\Core\Branding\Http\Requests;

use App\Core\Branding\Models\BrandAsset;
use Illuminate\Validation\Rule;

/**
 * BR-02, BR-04: upload a logo, favicon or sign-in background
 * (`core.theme.edit` anywhere). JPEG, PNG or WebP whose content really is
 * an image, as item images: logos and backgrounds at most 2 MB and 8000
 * pixels a side, favicons at most 256 KB and 512 pixels a side.
 */
class StoreBrandAssetRequest extends BrandAssetRequest
{
    public const MAX_KILOBYTES = 2048;

    public const FAVICON_MAX_KILOBYTES = 256;

    protected array $permissions = ['core.theme.edit'];

    public function rules(): array
    {
        $favicon = $this->input('kind') === BrandAsset::FAVICON;

        return [
            'kind' => ['required', 'string', Rule::in(BrandAsset::KINDS)],
            'file' => [
                'required', 'file',
                'max:'.($favicon ? self::FAVICON_MAX_KILOBYTES : self::MAX_KILOBYTES),
                'mimetypes:image/jpeg,image/png,image/webp',
                $favicon ? 'dimensions:min_width=16,min_height=16,max_width=512,max_height=512' : 'dimensions:min_width=1,min_height=1,max_width=8000,max_height=8000',
            ],
        ];
    }

    public function attributes(): array
    {
        return ['kind' => __('branding.attributes.kind'), 'file' => __('branding.attributes.file')];
    }

    public function messages(): array
    {
        $favicon = $this->input('kind') === BrandAsset::FAVICON;

        return [
            'file.max' => __('branding.errors.file_too_large', ['max' => $favicon ? '256 KB' : '2 MB']),
            'file.mimetypes' => __('branding.errors.file_type'),
            'file.dimensions' => $favicon ? __('branding.errors.favicon_size') : __('branding.errors.file_unreadable'),
        ];
    }
}
