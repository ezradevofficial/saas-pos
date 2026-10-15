<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\FixedAssets\CustomFields\AssetEntity;

/**
 * PATCH fixed-assets/assets/{id} (FA-04): `fixed_assets.asset.manage` at
 * the asset's location (and at a new location of a draft); the fields of
 * StoreAssetRequest, each optional. An active asset takes only its
 * descriptive fields.
 */
class UpdateAssetRequest extends AssetRequest
{
    public function rules(): array
    {
        return [...StoreAssetRequest::fields(required: false), 'location_id' => ['sometimes', 'uuid', Rule::exists('locations', 'id')]];
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && $this->filled('location_id') && ! $this->access()->at($this->user(), 'fixed_assets.asset.manage', (string) $this->input('location_id'))) {
                    $validator->errors()->add('location_id', __('fixed_assets.errors.location_forbidden'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, AssetEntity::KEY, $this->input('custom'), $this->asset(), $this->user()),
        ];
    }
}
