<?php

namespace Modules\FixedAssets\Http\Requests;

/** POST fixed-assets/documents/{id}/archive (FA-06): `fixed_assets.asset.manage` at the asset's location. */
class ArchiveDocumentRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_document';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.asset.view';
    }

    protected function permission(): ?string
    {
        return 'fixed_assets.asset.manage';
    }

    public function rules(): array
    {
        return [];
    }
}
