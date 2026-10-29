<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Payments\BillingWebhooks;
use App\Core\Payments\Pawapay\PawapaySignatures;
use Illuminate\Http\JsonResponse;
use App\Core\Billing\Http\Requests\BillingWebhookRequest;

/**
 * SUB-04: the providers' public webhooks for the platform's billing (the
 * URLs registered in the Paystack and pawaPay dashboards). Nothing here
 * marks an invoice paid on the strength of the request: BillingWebhooks asks
 * the provider again. A bad signature is refused with 401 and nothing else.
 */
class BillingWebhookController
{
    public function __construct(
        private readonly BillingWebhooks $webhooks,
        private readonly PawapaySignatures $signatures,
    ) {}

    /** POST billing/paystack/webhook: `x-paystack-signature` is HMAC SHA512 of the raw body. */
    public function paystack(BillingWebhookRequest $request): JsonResponse
    {
        abort_unless($this->webhooks->paystackSignatureValid($request->getContent(), $request->header('x-paystack-signature')), 401);

        $event = json_decode($request->getContent(), true);
        $this->webhooks->paystack(is_array($event) ? $event : [], $request->ip());

        return response()->json(['status' => 'ok']);
    }

    /** POST billing/pawapay/deposits: a deposit reached a final status; pawaPay is asked again. */
    public function pawapayDeposits(BillingWebhookRequest $request): JsonResponse
    {
        $this->authenticatePawapay($request);
        $this->webhooks->pawapayDeposit($request->json()->all(), $request->ip());

        return response()->json(['status' => 'ok']);
    }

    public function pawapayRefunds(BillingWebhookRequest $request): JsonResponse
    {
        $this->authenticatePawapay($request);
        $this->webhooks->pawapayRefund($request->json()->all(), $request->ip());

        return response()->json(['status' => 'ok']);
    }

    /** Payouts and checkouts are not used for billing: acknowledged. */
    public function pawapayAcknowledge(BillingWebhookRequest $request): JsonResponse
    {
        $this->authenticatePawapay($request);

        return response()->json(['status' => 'ok']);
    }

    private function authenticatePawapay(BillingWebhookRequest $request): void
    {
        abort_if($this->signatures->enabled() && ! $this->signatures->valid($request), 401);
    }
}
