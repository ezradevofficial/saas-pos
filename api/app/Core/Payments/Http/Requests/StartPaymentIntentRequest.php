<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST payments/intents (a paired till's token, TEN-05): ask for money for
 * a sale in progress.
 *
 * - `id`: the intent's UUID made on the till (optional; repeating it
 *   answers the same intent, so a retry never pushes twice);
 * - `payment_method_id`: an active method of the device's company;
 * - `mode`: `stk` (push to `phone`) or `manual` (the cashier confirms
 *   with the provider's `receipt`, e.g. an M-Pesa code);
 * - `amount_minor` (digits) and `currency`;
 * - `reference_type` and `reference`: what the money is for (a sale's
 *   module type and the UUID the till gave it);
 * - `user_id`: the cashier signed in at the till (optional).
 */
class StartPaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof Device;
    }

    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'payment_method_id' => ['required', 'uuid', Rule::exists('payment_methods', 'id')
                ->where('company_id', $this->companyId())
                ->where('active', true)
                ->whereNull('archived_at')],
            'purpose' => ['sometimes', 'string', Rule::in(['sale'])],
            'mode' => ['required', 'string', Rule::in(['stk', 'manual'])],
            'amount_minor' => ['required', 'string', 'regex:/^[1-9]\d{0,15}\z/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'phone' => ['required_if:mode,stk', 'nullable', 'string', 'max:20'],
            'receipt' => ['required_if:mode,manual', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{6,20}\z/'],
            'reference_type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/', 'max:40'],
            'reference' => ['required', 'uuid'],
            'user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'active')],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.exists' => __('payments.errors.method_unavailable'),
            'currency.exists' => __('core.currency.not_active'),
            'receipt.regex' => __('payments.errors.receipt_invalid'),
        ];
    }

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::query()->findOrFail($this->validated('payment_method_id'));
    }

    private function companyId(): ?string
    {
        $device = $this->user();

        return $device instanceof Device ? $device->location()->with('branch')->first()?->branch?->company_id : null;
    }
}
