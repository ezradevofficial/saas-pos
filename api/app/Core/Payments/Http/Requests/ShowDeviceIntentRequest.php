<?php

namespace App\Core\Payments\Http\Requests;

use App\Core\Payments\Models\PaymentIntent;
use App\Core\Tenancy\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET payments/intents/{payment_intent} (a till polling): only intents
 * made at the device's own location are found; any other is 404.
 */
class ShowDeviceIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->user();
        $intent = $this->route('payment_intent');

        abort_unless($device instanceof Device && $intent instanceof PaymentIntent && $intent->location_id === $device->location_id, 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
