<?php

namespace Modules\Manufacturing\Http\Requests;

/**
 * POST manufacturing/boms/{id}/versions (MFG-03): not found unless the user
 * sees the BOM; `manufacturing.bom.manage` in its company (403);
 * `{copy_from_id?}` (a version of this BOM; default its latest).
 */
class NewVersionRequest extends BomActionRequest
{
    public function rules(): array
    {
        return ['copy_from_id' => ['sometimes', 'nullable', 'uuid']];
    }
}
