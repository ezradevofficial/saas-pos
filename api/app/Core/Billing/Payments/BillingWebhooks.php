<?php

namespace App\Core\Billing\Payments;

use App\Core\Audit\AuditContext;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Operator\Operator;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Pawapay\PawapayCallbacks;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\Paystack\PaystackProvider;
use Illuminate\Support\Str;

/**
 * SUB-04: the providers' webhooks for the platform's own billing (Paystack
 * and pawaPay, public URLs the owner registered in their dashboards). A
 * webhook only says that something happened to a payment id: the payment is
 * then asked of the provider (Paystack's verify, pawaPay's deposit status)
 * and only that answer is applied, with the exact amount and currency
 * checked. Idempotent: a payment's final status never changes, so a repeated
 * webhook changes nothing. Runs in the operator's context; audit entries name
 * no user (AUD-02).
 */
class BillingWebhooks
{
    public function __construct(
        private readonly Operator $operator,
        private readonly PaymentIntents $intents,
        private readonly PawapayCallbacks $pawapay,
        private readonly BillingPayments $payments,
        private readonly AuditContext $audit,
    ) {}

    /** Whether $signature is HMAC SHA512 of $body under one of the operator's Paystack keys. */
    public function paystackSignatureValid(string $body, ?string $signature): bool
    {
        if (blank($signature)) {
            return false;
        }

        foreach ($this->operator->run(fn () => PaystackProvider::webhookKeys()) as $key) {
            if (hash_equals(PaystackProvider::signatureOf($body, $key), strtolower(trim((string) $signature)))) {
                return true;
            }
        }

        return false;
    }

    /** A signed Paystack event: only a successful charge is looked into (verify), the rest is acknowledged. */
    public function paystack(array $event, ?string $ip): void
    {
        $this->system($ip);
        $reference = $event['data']['reference'] ?? null;

        if (($event['event'] ?? null) !== 'charge.success' || ! is_string($reference) || ! Str::isUuid($reference)) {
            return;
        }

        $intent = $this->operator->run(function () use ($reference) {
            $intent = PaymentIntent::query()->where('driver', 'paystack')->where('purpose', 'sale')->find(strtolower($reference));

            return $intent === null ? null : $this->intents->recheck($intent);
        });

        if ($intent !== null) {
            $this->settle($intent);
        }
    }

    public function pawapayDeposit(array $body, ?string $ip): void
    {
        $this->system($ip);

        $intent = $this->operator->run(fn () => $this->pawapay->deposit($body));

        if ($intent !== null) {
            $this->settle($intent);
        }
    }

    public function pawapayRefund(array $body, ?string $ip): void
    {
        $this->system($ip);
        $this->operator->run(fn () => $this->pawapay->refund($body));
    }

    /** The operator invoice of a settled intent is marked paid (or not), and the customer's copy follows. */
    private function settle(PaymentIntent $intent): void
    {
        if ($intent->reference_type === Invoicing::REFERENCE_TYPE && ! $intent->isPending() && $intent->status !== 'unknown') {
            $this->payments->settle((string) $intent->id, (string) $intent->reference);
        }
    }

    private function system(?string $ip): void
    {
        $this->audit->reset();
        $this->audit->setIp($ip);
    }
}
