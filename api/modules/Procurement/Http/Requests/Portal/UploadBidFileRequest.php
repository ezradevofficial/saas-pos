<?php

namespace Modules\Procurement\Http\Requests\Portal;

use App\Core\CustomFields\CustomFieldFiles;

/** POST supplier-portal/bid/files (PRC-09, PRC-23): one file of the core types, at most 10 MB, `{file}`. */
class UploadBidFileRequest extends PortalRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:'.CustomFieldFiles::MAX_KB]];
    }
}
