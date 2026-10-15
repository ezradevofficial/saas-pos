<?php

namespace Modules\Manufacturing\Http\Requests;

/**
 * POST manufacturing/boms/{id}/archive (MFG-02): not found unless the user
 * sees the BOM; `manufacturing.bom.manage` in its company (403).
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
        return [];
    }
}
