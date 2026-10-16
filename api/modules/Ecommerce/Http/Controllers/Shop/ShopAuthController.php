<?php

namespace Modules\Ecommerce\Http\Controllers\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Customers\CustomerAuth;
use Modules\Ecommerce\Http\Requests\CustomerCodeRequest;
use Modules\Ecommerce\Http\Requests\CustomerVerifyRequest;
use Modules\Ecommerce\Models\Address;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Support\EcomError;

/**
 * ECOM-08: a customer's sign-in by a one-time code and their account (the
 * orders they placed in this store, their saved addresses). The session
 * is the bearer token ResolveShop checks; the account needs one (401
 * `customer_session_required`).
 */
class ShopAuthController
{
    public function __construct(private readonly CustomerAuth $auth) {}

    public function code(CustomerCodeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->auth->start(ShopController::store($request), (string) $request->validated('contact'))], 201);
    }

    public function verify(CustomerVerifyRequest $request): JsonResponse
    {
        $result = $this->auth->verify(ShopController::store($request), (string) $request->validated('challenge_id'), (string) $request->validated('code'));

        return response()->json(['data' => ['token' => $result['token'], 'customer' => ['name' => $result['customer']->name, 'phone' => $result['customer']->phone, 'email' => $result['customer']->email]]]);
    }

    public function signOut(Request $request): JsonResponse
    {
        $session = ShopController::session($request) ?? throw EcomError::make('customer_session_required', status: 401);
        $this->auth->signOut($session);

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function account(Request $request): JsonResponse
    {
        $session = ShopController::session($request) ?? throw EcomError::make('customer_session_required', status: 401);
        $store = ShopController::store($request);
        $customer = $session->customer;

        return response()->json(['data' => [
            'customer' => ['name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email],
            'orders' => Order::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->orderByDesc('placed_at')->limit(50)->get()
                ->map(fn (Order $o) => ['number' => $o->number, 'status' => $o->status, 'payment_status' => $o->payment_status, 'currency' => $o->currency,
                    'total_minor' => (string) $o->total_minor, 'placed_at' => $o->placed_at->toIso8601String()])->values(),
            'addresses' => Address::query()->where('customer_id', $customer->id)->whereNull('archived_at')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Address $a) => ['id' => $a->id, 'zone_id' => $a->zone_id, 'area' => $a->area, 'line' => $a->line, 'notes' => $a->notes])->values(),
        ]]);
    }
}
