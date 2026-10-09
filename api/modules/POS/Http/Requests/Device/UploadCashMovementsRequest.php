<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Models\CashMovement;

/** POST pos/cash-movements: cash paid in or out of the drawer (POS-04). */
class UploadCashMovementsRequest extends FormRequest
{
    use UploadRules;

    public function rules(): array
    {
        return [
            'movements' => ['required', 'array', 'min:1', 'max:50'],
            'movements.*.id' => $this->deviceId(),
            'movements.*.shift_id' => ['required', 'uuid'],
            'movements.*.user_id' => ['required', 'uuid'],
            'movements.*.kind' => ['required', 'string', 'in:'.implode(',', CashMovement::KINDS)],
            'movements.*.currency' => $this->currencyCode(),
            'movements.*.amount_minor' => $this->minor(positive: true),
            'movements.*.reason' => ['required', 'string', 'max:500'],
            'movements.*.occurred_at' => ['required', 'date'],
            ...$this->actorProof('movements.*.actor_proof'),
            ...$this->overrideRules('movements.*.override'),
        ];
    }
}
