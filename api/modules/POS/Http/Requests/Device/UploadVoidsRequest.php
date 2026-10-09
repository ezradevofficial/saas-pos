<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** POST pos/voids: whole sales voided at the till (POS-05, AUTH-08). */
class UploadVoidsRequest extends FormRequest
{
    use UploadRules;

    public function rules(): array
    {
        return [
            'voids' => ['required', 'array', 'min:1', 'max:50'],
            'voids.*.id' => $this->deviceId(),
            'voids.*.sale_id' => ['required', 'uuid'],
            'voids.*.voided_by_id' => ['required', 'uuid'],
            'voids.*.voided_at' => ['required', 'date'],
            'voids.*.reason' => ['required', 'string', 'max:500'],
            ...$this->actorProof('voids.*.actor_proof'),
            ...$this->overrideRules('voids.*.override'),
        ];
    }
}
