<?php

namespace Modules\Manufacturing\Http\Requests;

/**
 * PUT manufacturing/bom-versions/{id} (MFG-03, MFG-04): not found unless
 * the user sees the BOM; `manufacturing.bom.manage` at its company (company scope, review fix 3) (403);
 * `{output_qty, output_uom_id?, labour_minutes?, note?, lines: [{kind?,
 * item_id, uom_id?, qty, yield_percent?, wastage_percent?, explode?,
 * cost_share_percent?, note?}]}` (a draft only, 409 `version_published`).
 */
class SaveVersionRequest extends ShowBomRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->manages();
    }

    public function rules(): array
    {
        return ManufacturingRules::version('', required: true);
    }
}
