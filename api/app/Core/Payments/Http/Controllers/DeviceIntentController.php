<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Payments\Http\Requests\ShowDeviceIntentRequest;
use App\Core\Payments\Http\Requests\StartPaymentIntentRequest;
use App\Core\Payments\Http\Resources\PaymentIntentResource;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Tenancy\Models\Device;
use Illuminate\Http\JsonResponse;

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

        $intent = $this->intents->startFromDevice($device, $request->paymentMethod(), $request->validated());

        return PaymentIntentResource::make($intent)->response()->setStatusCode($known ? 200 : 201);
    }

    public function show(ShowDeviceIntentRequest $request, PaymentIntent $paymentIntent): PaymentIntentResource
    {
        return PaymentIntentResource::make($paymentIntent);
    }
}
