<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Payments\Http\Requests\ShowDeviceIntentRequest;
use App\Core\Payments\Http\Requests\StartPaymentIntentRequest;
use App\Core\Payments\Http\Resources\PaymentIntentResource;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\Phones;
use App\Core\Tenancy\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The till's payment calls (device token, TEN-05). POST starts an intent
 * (an STK push, or a manual confirmation with the provider's code) and
 * answers it at once: 201 when new, 200 when the id was already used by
 * this device. The till then polls GET until the status is final.
 */
class DeviceIntentController
{
    public function __construct(private readonly PaymentIntents $intents) {}

    public function store(StartPaymentIntentRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $id = $request->validated('id');
        $known = $id !== null && PaymentIntent::query()->whereKey($id)->exists();

        // A retry of a known id answers the same intent and spends no attempt.
        if (! $known) {
            $this->throttle($device, $request->validated());
        }

        $intent = $this->intents->startFromDevice($device, $request->paymentMethod(), $request->validated());

        return PaymentIntentResource::make($intent)->response()->setStatusCode($known ? 200 : 201);
    }

    /**
     * Limits against prompt spam and enumeration: `payments.device_intents_per_minute`
     * per device, and `payments.phone_pushes_per_5_minutes` per phone and
     * method for pushes (429 `too_many_requests`).
     */
    private function throttle(Device $device, array $data): void
    {
        $keys = [['payments:device:'.$device->id, (int) config('payments.device_intents_per_minute', 10), 60]];

        if (($data['mode'] ?? null) === 'stk' && filled($data['phone'] ?? null)) {
            $phone = Phones::kenyanMobile((string) $data['phone']) ?? preg_replace('/\D/', '', (string) $data['phone']);
            $keys[] = ['payments:phone:'.$data['payment_method_id'].':'.hash('sha256', (string) $phone), (int) config('payments.phone_pushes_per_5_minutes', 3), 300];
        }

        foreach ($keys as [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ApiException(429, 'too_many_requests', __('payments.errors.too_many_requests'), [], [], ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }

        foreach ($keys as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }

    public function show(ShowDeviceIntentRequest $request, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        return PaymentIntentResource::make($paymentIntent);
    }
}
