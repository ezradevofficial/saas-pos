<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/categories/{id}/archive|restore (FA-02, TEN-06): `fixed_assets.category.manage` at its company. */
class CategoryActionRequest extends RecordRequest
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
        return [];
    }
}
