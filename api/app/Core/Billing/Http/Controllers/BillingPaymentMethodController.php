<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Http\Requests\CompleteCardSetupRequest;
use App\Core\Billing\Http\Requests\ManageSubscriptionRequest;
use App\Core\Billing\Http\Requests\StoreBillingPaymentMethodRequest;
use App\Core\Billing\Http\Requests\SubscriptionRequest;
use App\Core\Billing\Http\SubscriptionPresenter;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\Billing\Payments\BillingPayments;
use Illuminate\Http\JsonResponse;

/** SUB-04: saved payment methods for the subscription (a wallet's phone, a card's token; never card data). */
class BillingPaymentMethodController
{
    public function __construct(private readonly BillingPayments $payments) {}

    public function index(SubscriptionRequest $request): JsonResponse
    {
        $methods = BillingPaymentMethod::query()->whereNull('archived_at')->orderByDesc('is_default')->orderBy('created_at')->get();

        return response()->json(['data' => $methods->map(fn (BillingPaymentMethod $m) => SubscriptionPresenter::method($m))->all()]);
    }

    /** A wallet is saved at once; a card answers the hosted page to open (`redirect_url`). */
    public function store(StoreBillingPaymentMethodRequest $request): JsonResponse
    {
        if ($request->validated('kind') === BillingPaymentMethod::CARD) {
            return response()->json(['data' => ['redirect_url' => $this->payments->startCardSetup($request->actor())]], 202);
        }

        $method = $this->payments->addWallet((string) $request->validated('provider'), (string) $request->validated('phone'), (bool) $request->validated('make_default', false), $request->actor());

        return response()->json(['data' => SubscriptionPresenter::method($method)], 201);
    }

    public function completeCard(CompleteCardSetupRequest $request): JsonResponse
    {
        $method = $this->payments->completeCardSetup((string) $request->validated('reference'), (bool) $request->validated('make_default', false), $request->actor());

        return response()->json(['data' => SubscriptionPresenter::method($method)], 201);
    }

    public function makeDefault(ManageSubscriptionRequest $request, BillingPaymentMethod $billingPaymentMethod): JsonResponse
    {
        return response()->json(['data' => SubscriptionPresenter::method($this->payments->makeDefault($billingPaymentMethod))]);
    }

    public function archive(ManageSubscriptionRequest $request, BillingPaymentMethod $billingPaymentMethod): JsonResponse
    {
        return response()->json(['data' => SubscriptionPresenter::method($this->payments->archive($billingPaymentMethod))]);
    }
}
