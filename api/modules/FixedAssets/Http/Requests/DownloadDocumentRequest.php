<?php

namespace Modules\FixedAssets\Http\Requests;

/** GET fixed-assets/documents/{id}/download (FA-06): `fixed_assets.asset.view` at the asset's location; an archived file is not served. */
class DownloadDocumentRequest extends RecordRequest
{
    protected function parameter(): string
    {
        return 'fa_document';
    }

    protected function viewPermission(): ?string
    {
        return 'fixed_assets.asset.view';
    }

    public function rules(): array
    {
        return [];
    }
}
