<?php

namespace Modules\Ecommerce\Http\Controllers\Shop;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Customers\CodeChallenges;
use Modules\Ecommerce\Customers\CustomerAuth;
use Modules\Ecommerce\Customers\MarketingConsent;
use Modules\Ecommerce\Http\Requests\CustomerCodeRequest;
use Modules\Ecommerce\Http\Requests\CustomerConsentRequest;
use Modules\Ecommerce\Http\Requests\CustomerSignOutRequest;
use Modules\Ecommerce\Http\Requests\CustomerVerifyRequest;
use Modules\Ecommerce\Models\Address;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Support\EcomError;

/**
 * ECOM-08: a customer's sign-in by a one-time code and their account (the
 * orders they placed in this store, their saved addresses). The session
 * is the bearer token ResolveShop checks; the account needs one (401
 * `customer_session_required`). Marketing consent (NFR-08) is asked at
 * sign-in, unticked by default, and changed on the account page.
 */
class ShopAuthController
{
    public function __construct(
        private readonly CustomerAuth $auth,
        private readonly CodeChallenges $challenges,
        private readonly MarketingConsent $marketing,
    ) {}

    /** ECOM-08: past a few codes from one address, a challenge is answered first (428 carries it). */
    public function code(CustomerCodeRequest $request): JsonResponse
    {
        $ip = (string) $request->ip();

        if ($this->challenges->required($ip) && ! $this->challenges->passes($request->validated('challenge'))) {
            throw $this->challenges->refusal();
        }

        $result = $this->auth->start(ShopController::store($request), (string) $request->validated('contact'));
        $this->challenges->count($ip);

        return response()->json(['data' => $result], 201);
    }

    public function verify(CustomerVerifyRequest $request): JsonResponse
    {
        $store = ShopController::store($request);
        $result = $this->auth->verify($store, (string) $request->validated('challenge_id'), (string) $request->validated('code'));
        $customer = $result['customer'];

        // The sign-in has happened: a consent that cannot be recorded (the party could not be
        // made) is reported and answered as not given, never as a failed sign-in.
        if ($request->boolean('marketing_consent')) {
            rescue(fn () => $this->marketing->signUp($store, $customer, (string) $request->validated('wording_version'), (string) $request->validated('challenge_id')), report: true);
        }

        return response()->json(['data' => ['token' => $result['token'], 'customer' => [
            'name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email, 'marketing_consent' => $this->marketing->current($customer->refresh()),
        ]]]);
    }

    public function signOut(CustomerSignOutRequest $request): JsonResponse
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
            'customer' => ['name' => $customer->name, 'phone' => $customer->phone, 'email' => $customer->email, 'marketing_consent' => $this->marketing->current($customer)],
            'orders' => Order::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->orderByDesc('placed_at')->limit(50)->get()
                ->map(fn (Order $o) => ['number' => $o->number, 'status' => $o->status, 'payment_status' => $o->payment_status, 'currency' => $o->currency,
                    'total_minor' => (string) $o->total_minor, 'placed_at' => $o->placed_at->toIso8601String()])->values(),
            'addresses' => Address::query()->where('customer_id', $customer->id)->whereNull('archived_at')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (Address $a) => ['id' => $a->id, 'zone_id' => $a->zone_id, 'area' => $a->area, 'line' => $a->line, 'notes' => $a->notes])->values(),
        ]]);
    }

    /** NFR-08: the signed-in customer turns marketing messages on (with the wording shown) or off. */
    public function marketingConsent(CustomerConsentRequest $request): JsonResponse
    {
        $session = ShopController::session($request) ?? throw EcomError::make('customer_session_required', status: 401);
        $customer = $session->customer;
        $this->marketing->set(ShopController::store($request), $customer, $request->boolean('granted'), $request->validated('wording_version'));

        return response()->json(['data' => ['marketing_consent' => $this->marketing->current($customer->refresh())]]);
    }
}
