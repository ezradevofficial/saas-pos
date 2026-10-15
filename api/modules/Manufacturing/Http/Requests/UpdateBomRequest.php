<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\CustomFields\BomEntity;

/**
 * PUT manufacturing/boms/{id} (MFG-02, MFG-22): not found unless the user
 * sees the BOM; `manufacturing.bom.manage` in its company (403);
 * `{name?, custom?}`.
 */
class UpdateBomRequest extends ShowBomRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->manages();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, BomEntity::KEY, $this->input('custom'), $this->bom(), $this->user())];
    }
}
