<?php

namespace Modules\FixedAssets\Http\Requests;

/** PATCH fixed-assets/categories/{id} (FA-02): `fixed_assets.category.manage` at its company; the fields of a category, each optional. */
class UpdateCategoryRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_category';
    }

    protected function viewPermission(): ?string
    {
        return null;
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.category.manage';
    }

    public function rules(): array
    {
        return CategoryRules::rules(required: false);
    }
}
