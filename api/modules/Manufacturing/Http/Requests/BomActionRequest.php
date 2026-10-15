<?php

namespace Modules\Manufacturing\Http\Requests;

/**
 * POST manufacturing/boms/{id}/archive, POST manufacturing/boms/{id}/versions
 * (`{copy_from_id?}`) (MFG-02, MFG-03): not found unless the user sees the
 * BOM; `manufacturing.bom.manage` in its company (403).
 */
class BomActionRequest extends ShowBomRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->manages();
    }

    public function rules(): array
    {
        return ['copy_from_id' => ['sometimes', 'nullable', 'uuid']];
    }
}
