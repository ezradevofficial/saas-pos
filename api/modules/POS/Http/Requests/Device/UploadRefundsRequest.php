<?php

namespace Modules\POS\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** POST pos/refunds: lines of sales given back and refunded at the till (POS-05, RBAC-06, AUTH-08). */
class UploadRefundsRequest extends FormRequest
{
    use UploadRules;

    public function rules(): array
    {
        return [
            'refunds' => ['required', 'array', 'min:1', 'max:50'],
            'refunds.*.id' => $this->deviceId(),
            'refunds.*.sale_id' => ['required', 'uuid'],
            'refunds.*.shift_id' => ['required', 'uuid'],
            'refunds.*.cashier_id' => ['required', 'uuid'],
            'refunds.*.receipt_seq' => ['required', 'integer', 'min:1'],
            'refunds.*.receipt_number' => ['required', 'string', 'max:80'],
            'refunds.*.refunded_at' => ['required', 'date'],
            'refunds.*.reason' => ['required', 'string', 'max:500'],
            'refunds.*.actor_proof' => $this->actorProof(),
            'refunds.*.number_range_id' => ['nullable', 'uuid'],
            'refunds.*.total_minor' => $this->minor(positive: true),
            'refunds.*.lines' => ['required', 'array', 'min:1', 'max:500'],
            'refunds.*.lines.*.id' => $this->deviceId(),
            'refunds.*.lines.*.sale_line_id' => ['required', 'uuid'],
            'refunds.*.lines.*.qty' => $this->quantity(),
            ...$this->paymentRules('refunds.*.payments'),
            ...$this->overrideRules('refunds.*.override'),
        ];
    }
}
