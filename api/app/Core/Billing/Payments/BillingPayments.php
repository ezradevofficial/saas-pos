<?php

namespace App\Core\Billing\Payments;

use App\Core\Audit\Auditor;
use App\Core\Billing\Dunning\BillingCycle;
use App\Core\Billing\Dunning\BillingNotices;
use App\Core\Billing\Events\OperatorInvoicePaid;
use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Contracts\HostedCheckout;
use App\Core\Payments\Contracts\PaymentProvider;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Pawapay\PawapayProvider;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Payments\Paystack\PaystackProvider;
use App\Core\Payments\Phones;
use App\Core\Payments\ProviderResult;
use App\Core\Platform\PlatformAuditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SUB-04, SUB-06: paying the platform's invoices.
 *
 * The money is collected by the operator: a payment intent of the
 * operator tenant, through its selling company's payment method for the
 * provider (M-Pesa on its Daraja method; Airtel Money, Orange Money,
 * Afrimoney and card through their adapters, `billing.drivers`), with
 * reference `billing.invoice` and the operator invoice's id. The
 * customer keeps its view of the payment (BillingPayment, same id).
 *
 * A settled intent (PaymentIntentSettled, listener SettleBillingPayment)
 * marks the operator invoice paid, then, in the customer's context, its
 * copy, the payment and the subscription (active again at once). Staff
 * mark bank transfers paid the same way (markPaid).
 *
 * Saved methods hold only a phone number or a provider's card token.
 */
class BillingPayments
{
    public const CARD = 'card_aggregator';

    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
        private readonly PaymentIntents $intents,
        private readonly PaymentProviderRegistry $registry,
        private readonly Subscriptions $subscriptions,
        private readonly BillingNotices $notices,
    ) {}

    /**
     * Pay the current tenant's open $invoice: with a saved method
     * (`payment_method_id`), or once with a wallet (`provider`, `phone`) or
     * a card checkout (`provider` card_aggregator).
     *
     * @param  array{payment_method_id?: ?string, provider?: ?string, phone?: ?string}  $how
     */
    public function pay(BillingInvoice $invoice, array $how, ?User $by): BillingPayment
    {
        if (! $invoice->isOpen()) {
            throw new ApiException(422, 'invoice_not_open', __('billing.errors.invoice_not_open'));
        }

        $subscription = $this->subscriptions->require();
        [$provider, $phone, $token, $saved, $label] = $this->resolve($subscription, $how);

        // Never two requests for one invoice: while one may still be paid (pending, or unknown at
        // the provider), ask where it stands; still open, a new one is refused.
        foreach (BillingPayment::query()->where('billing_invoice_id', $invoice->id)->whereIn('status', ['pending', 'unknown'])->get() as $open) {
            if ($this->refresh($open)->isPending()) {
                throw new ApiException(409, 'payment_in_progress', __('billing.errors.payment_in_progress'));
            }
        }

        if (! $invoice->fresh()->isOpen()) {
            throw new ApiException(422, 'invoice_not_open', __('billing.errors.invoice_not_open'));
        }
        $customerId = $this->tenants->require();
        $id = (string) Str::uuid7();
        // Paystack needs the payer's email: the saved card's, else the person paying.
        $email = $saved?->customer_email ?? $by?->email;

        // The customer's row first: a settlement arriving at once finds it.
        $payment = BillingPayment::create([
            'id' => $id,
            'billing_invoice_id' => $invoice->id,
            'payment_method_id' => $saved?->id,
            'provider' => $provider,
            'label' => $label,
            'currency' => $invoice->currency,
            'amount_minor' => $invoice->total_minor,
            'status' => 'pending',
            'created_by' => $by?->id,
        ]);

        try {
            $intent = $this->operator->run(function () use ($invoice, $provider, $phone, $token, $customerId, $id, $email) {
                $operatorInvoice = OperatorInvoice::query()->findOrFail($invoice->operator_invoice_id);

                if ($operatorInvoice->customer_tenant_id !== $customerId || ! $operatorInvoice->isOpen()) {
                    throw new ApiException(422, 'invoice_not_open', __('billing.errors.invoice_not_open'));
                }

                [$method, $driver] = $this->collector($operatorInvoice->company_id, $provider);
                $card = $provider === self::CARD;
                $initiate = $this->initiator($driver, $method, $token, $email);

                return $this->intents->request($method, $driver, [
                    'id' => $id,
                    'company_id' => $operatorInvoice->company_id,
                    'payment_method_id' => $method->id,
                    'provider' => $provider,
                    'purpose' => 'sale',
                    'mode' => $card ? 'checkout' : 'stk',
                    'currency' => $operatorInvoice->currency,
                    'amount_minor' => $operatorInvoice->total_minor,
                    'phone' => $phone,
                    'reference_type' => Invoicing::REFERENCE_TYPE,
                    'reference' => $operatorInvoice->id,
                ], $initiate);
            });
        } catch (\Throwable $e) {
            // Never left pending: the request did not start.
            $payment->fill(['status' => 'failed', 'message' => $e instanceof ApiException ? $e->getMessage() : __('billing.errors.payment_failed')])->save();

            throw $e;
        }

        return $this->mirror($payment->fresh(), $intent);
    }

    /**
     * How the provider is asked for the money, when not by its plain initiate():
     * Paystack opens its checkout for the payer's email or charges the saved
     * card's authorization; other hosted card providers charge their token.
     *
     * @return (callable(PaymentIntent): ProviderResult)|null
     */
    private function initiator(PaymentProvider $driver, PaymentMethod $method, ?string $token, ?string $email): ?callable
    {
        if ($driver instanceof PaystackProvider) {
            if (blank($email)) {
                throw new ApiException(422, 'payer_email_missing', __('billing.errors.payer_email_missing'));
            }

            return $token !== null
                ? fn (PaymentIntent $intent) => $driver->chargeAuthorization($intent, $method, $token, $email)
                : fn (PaymentIntent $intent) => $driver->checkout($intent, $method, $email);
        }

        return $token !== null && $driver instanceof HostedCheckout ? fn (PaymentIntent $intent) => $driver->charge($intent, $method, $token) : null;
    }

    /** Ask the provider where an open payment stands (the screen polls this), then show it. */
    public function refresh(BillingPayment $payment): BillingPayment
    {
        if (! $payment->isPending()) {
            return $payment;
        }

        $intent = $this->operator->run(function () use ($payment) {
            $intent = PaymentIntent::query()->find($payment->id);

            return $intent === null ? null : $this->intents->check($intent);
        });

        if ($intent === null) {
            return $payment;
        }

        // Settled now (idempotent): the screen does not wait for the queued listener.
        if (! in_array($intent->status, ['pending', 'unknown'], true)) {
            $this->settle($intent->id, (string) $intent->reference);
        }

        return $this->mirror($payment->fresh(), $intent);
    }

    /**
     * An operator payment intent for an invoice reached a final status
     * (PaymentIntentSettled in the operator's tenant): a success for the
     * invoice's amount marks it paid; the customer's payment row follows.
     */
    public function settle(string $intentId, string $operatorInvoiceId): void
    {
        // Exactly once: the invoice row is locked while its payment is decided.
        [$intent, $invoice, $paidNow] = $this->operator->run(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($intentId, $operatorInvoiceId) {
            $intent = PaymentIntent::query()->find($intentId);
            $invoice = OperatorInvoice::query()->lockForUpdate()->find($operatorInvoiceId);

            if ($intent === null || $invoice === null || $intent->reference !== $invoice->id) {
                return [null, null, false];
            }

            $succeeded = $intent->status === 'succeeded' && $intent->currency === $invoice->currency;

            if ($succeeded && $invoice->isOpen() && $intent->amount_minor === $invoice->total_minor) {
                $this->markOperatorPaid($invoice, 'intent', $intent->provider_receipt ?? $intent->id);

                return [$intent, $invoice, true];
            }

            // Money for an invoice already paid (a second payment raced the first): kept as an overpayment for staff.
            if ($succeeded && ! $invoice->isOpen() && $invoice->payment_reference !== ($intent->provider_receipt ?? $intent->id)) {
                $this->recordOverpayment($invoice, $intent);
            }

            return [$intent, $invoice, false];
        }));

        if ($intent === null) {
            return;
        }

        $this->tenants->run($invoice->customer_tenant_id, function () use ($intent, $invoice) {
            $payment = BillingPayment::query()->find($intent->id);

            if ($payment !== null) {
                $this->mirror($payment, $intent);
                $this->keepCard($payment, $intent);
            }

            if ($invoice->status === BillingInvoice::PAID) {
                $this->copyPaid($invoice);
            }
        });
    }

    /**
     * SUB-04: a card paid on Paystack's page with a reusable authorization is
     * saved as a payment method (brand, last4 and expiry only; the
     * authorization code encrypted), once, for renewals. In the customer's context.
     */
    private function keepCard(BillingPayment $payment, PaymentIntent $intent): void
    {
        if ($intent->driver !== 'paystack' || $intent->status !== 'succeeded' || $payment->payment_method_id !== null) {
            return;
        }

        $card = PaystackProvider::takeAuthorization((string) $intent->id);
        $subscription = Subscription::query()->first();

        if ($card === null || $subscription === null) {
            return;
        }

        $label = mb_substr(trim(ucfirst(strtolower($card['brand'])).' •••• '.$card['last4'].($card['exp_month'] !== '' ? ' · '.$card['exp_month'].'/'.substr($card['exp_year'], -2) : '')), 0, 60);
        $email = $payment->created_by === null ? null : User::query()->find($payment->created_by)?->email;

        $method = BillingPaymentMethod::query()->whereNull('archived_at')->where('provider', self::CARD)->where('label', $label)->first();

        if ($method !== null) {
            $method->fill(['token' => $card['code'], 'customer_email' => $email ?? $method->customer_email])->save();
        } else {
            $method = BillingPaymentMethod::create([
                'kind' => BillingPaymentMethod::CARD,
                'provider' => self::CARD,
                'label' => $label,
                'token' => $card['code'],
                'customer_email' => $email,
                'is_default' => false,
                'auto_renew' => true,
                'created_by' => $payment->created_by,
            ]);
            $this->defaultIfFirst($subscription, $method, false);
        }

        $payment->fill(['payment_method_id' => $method->id])->save();
    }

    /** In the operator's context, the invoice locked: an extra payment recorded once, alerted in the console and the platform audit. */
    private function recordOverpayment(OperatorInvoice $invoice, PaymentIntent $intent): void
    {
        $overpayments = $invoice->overpayments ?? [];

        if (in_array($intent->id, array_column($overpayments, 'intent_id'), true)) {
            return;
        }

        $entry = ['intent_id' => $intent->id, 'amount_minor' => $intent->amount_minor, 'currency' => $intent->currency, 'receipt' => $intent->provider_receipt, 'at' => CarbonImmutable::now()->toIso8601String()];
        $invoice->fill(['overpayments' => [...$overpayments, $entry]])->save();

        $account = OperatorAccount::query()->where('customer_tenant_id', $invoice->customer_tenant_id)->first();
        $account?->fill(['alerts' => [...($account->alerts ?? []), ['kind' => 'overpayment', 'invoice' => $invoice->number, ...$entry]]])->save();

        app(PlatformAuditor::class)->record('platform.invoice.overpaid', $invoice, null, ['tenant_id' => $invoice->customer_tenant_id, ...$entry]);
    }

    /**
     * Staff (billing role) record money received outside the providers
     * (bank transfer for Enterprise, SUB-04): the operator invoice is paid,
     * then the customer's copy and subscription. Run outside any tenant.
     */
    public function markPaid(OperatorInvoice $invoice, string $via, string $reference): OperatorInvoice
    {
        // The lock holds to commit, so the payables recorded with it (MKT-03) commit with the payment.
        $invoice = $this->operator->run(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($invoice, $via, $reference) {
            $fresh = OperatorInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! $fresh->isOpen()) {
                throw new ApiException(422, 'invoice_not_open', __('billing.errors.invoice_not_open'));
            }

            return $this->markOperatorPaid($fresh, $via, $reference);
        }));

        $this->tenants->run($invoice->customer_tenant_id, fn () => $this->copyPaid($invoice));

        return $invoice;
    }

    /**
     * Save a wallet (SUB-04). Renewals are requested through it
     * automatically (a push to the phone) when it is the default.
     */
    public function addWallet(string $provider, string $phone, bool $makeDefault, User $by): BillingPaymentMethod
    {
        $subscription = $this->subscriptions->require();
        [, $normalised] = $this->wallet($subscription, $provider, $phone);

        $method = BillingPaymentMethod::create([
            'kind' => BillingPaymentMethod::MOBILE_MONEY,
            'provider' => $provider,
            'label' => self::maskPhone($normalised),
            'phone' => $normalised,
            'is_default' => false,
            'auto_renew' => true,
            'created_by' => $by->id,
        ]);

        return $this->defaultIfFirst($subscription, $method, $makeDefault);
    }

    /** Start saving a card: the hosted page to open (SUB-04; nothing about the card reaches us). */
    public function startCardSetup(User $by): string
    {
        $this->subscriptions->require();
        $reference = Str::random(40);
        Cache::put($this->setupKey($reference), $by->id, now()->addMinutes(30));

        return $this->cardCheckout()->setupUrl($reference, rtrim((string) config('app.frontend_url'), '/').'/settings/subscription');
    }

    /** The payer is back from the hosted page: keep the provider's token and a label. */
    public function completeCardSetup(string $reference, bool $makeDefault, User $by): BillingPaymentMethod
    {
        $subscription = $this->subscriptions->require();

        if (Cache::pull($this->setupKey($reference)) !== $by->id) {
            throw new ApiException(422, 'card_setup_unknown', __('billing.errors.card_setup_unknown'), ['reference' => [__('billing.errors.card_setup_unknown')]]);
        }

        $card = $this->cardCheckout()->completeSetup($reference)
            ?? throw new ApiException(422, 'card_not_saved', __('billing.errors.card_not_saved'));

        $method = BillingPaymentMethod::create([
            'kind' => BillingPaymentMethod::CARD,
            'provider' => self::CARD,
            'label' => mb_substr($card['label'], 0, 60),
            'token' => $card['token'],
            'is_default' => false,
            'auto_renew' => true,
            'created_by' => $by->id,
        ]);

        return $this->defaultIfFirst($subscription, $method, $makeDefault);
    }

    public function makeDefault(BillingPaymentMethod $method): BillingPaymentMethod
    {
        if ($method->isArchived()) {
            throw new ApiException(422, 'payment_method_archived', __('billing.errors.payment_method_archived'));
        }

        $subscription = $this->subscriptions->require();
        BillingPaymentMethod::query()->where('is_default', true)->whereKeyNot($method->id)->get()
            ->each(fn (BillingPaymentMethod $other) => $other->fill(['is_default' => false])->save());
        $method->fill(['is_default' => true])->save();
        $subscription->fill(['default_payment_method_id' => $method->id])->save();

        return $method;
    }

    public function archive(BillingPaymentMethod $method): BillingPaymentMethod
    {
        $subscription = $this->subscriptions->require();

        if ($subscription->default_payment_method_id === $method->id) {
            $subscription->fill(['default_payment_method_id' => null])->save();
        }

        $method->fill(['is_default' => false])->save();
        $method->archive();

        return $method;
    }

    /** Auto-renewal: the default method requests the renewal, where it can (SUB-04). Never throws. */
    public function autoRenew(Subscription $subscription, BillingInvoice $invoice): ?BillingPayment
    {
        $method = $subscription->default_payment_method_id === null ? null : BillingPaymentMethod::query()->whereNull('archived_at')->find($subscription->default_payment_method_id);

        if ($method === null || ! $method->auto_renew) {
            return null;
        }

        try {
            return $this->pay($invoice, ['payment_method_id' => $method->id], null);
        } catch (ApiException $e) {
            report($e);

            return null;
        }
    }

    /** @return array{0: PaymentMethod, 1: PaymentProvider} the operator company's method for $provider and the adapter collecting it */
    private function collector(string $companyId, string $provider): array
    {
        $method = PaymentMethod::query()->where('company_id', $companyId)->where('provider', $provider)->whereNull('archived_at')->first();

        if ($method === null) {
            throw new ApiException(422, 'provider_unavailable', __('billing.errors.provider_unavailable'), ['provider' => [__('billing.errors.provider_unavailable')]]);
        }

        $name = config("billing.drivers.{$provider}") ?: $this->registry->driverName($method);

        // A real adapter collects only through a method the operator switched on (configured),
        // or with the operator's environment credentials (Paystack, pawaPay).
        $environment = ($name === 'paystack' && filled(config('payments.paystack.secret_key'))) || ($name === 'pawapay' && filled(config('payments.pawapay.api_token')));

        if ($name !== 'fake' && ! $method->active && ! $environment) {
            throw new ApiException(422, 'provider_unavailable', __('billing.errors.provider_unavailable'), ['provider' => [__('billing.errors.provider_unavailable')]]);
        }

        return [$method, $this->registry->driver($name)];
    }

    private function cardCheckout(): HostedCheckout
    {
        $driver = $this->registry->driver((string) (config('billing.drivers.'.self::CARD) ?: 'card_hosted'));

        return $driver instanceof HostedCheckout ? $driver : throw new ApiException(422, 'provider_unavailable', __('billing.errors.provider_unavailable'));
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string, 3: ?BillingPaymentMethod, 4: string} provider, phone, card token, saved method, label
     */
    private function resolve(Subscription $subscription, array $how): array
    {
        if (filled($how['payment_method_id'] ?? null)) {
            $saved = BillingPaymentMethod::query()->whereNull('archived_at')->find($how['payment_method_id'])
                ?? throw new ApiException(422, 'payment_method_unknown', __('billing.errors.payment_method_unknown'), ['payment_method_id' => [__('billing.errors.payment_method_unknown')]]);

            return [$saved->provider, $saved->phone, $saved->token, $saved, $saved->label];
        }

        $provider = (string) ($how['provider'] ?? '');

        if ($provider === self::CARD) {
            return [self::CARD, null, null, null, __('billing.methods.card_checkout')];
        }

        [, $phone] = $this->wallet($subscription, $provider, (string) ($how['phone'] ?? ''));

        return [$provider, $phone, null, null, self::maskPhone($phone)];
    }

    /** @return array{0: string, 1: string} a wallet of the subscription's country and its phone, normalised */
    private function wallet(Subscription $subscription, string $provider, string $phone): array
    {
        if (! in_array($provider, (array) config("billing.wallets.{$subscription->country}", []), true)) {
            throw new ApiException(422, 'provider_unavailable', __('billing.errors.provider_unavailable'), ['provider' => [__('billing.errors.provider_unavailable')]]);
        }

        $normalised = match ($subscription->country) {
            'KE' => Phones::kenyanMobile($phone),
            'CD' => PawapayProvider::msisdn($phone),
            default => preg_replace('/\D/', '', $phone),
        };

        if ($normalised === null || strlen((string) preg_replace('/\D/', '', $normalised)) < 9 || strlen((string) $normalised) > 16) {
            throw new ApiException(422, 'phone_invalid', __('payments.errors.phone_invalid'), ['phone' => [__('payments.errors.phone_invalid')]]);
        }

        return [$provider, (string) $normalised];
    }

    private function defaultIfFirst(Subscription $subscription, BillingPaymentMethod $method, bool $makeDefault): BillingPaymentMethod
    {
        $hasDefault = BillingPaymentMethod::query()->whereNull('archived_at')->where('is_default', true)->whereKeyNot($method->id)->exists();

        return $makeDefault || ! $hasDefault ? $this->makeDefault($method) : $method;
    }

    /** The customer's payment row follows the intent. */
    private function mirror(BillingPayment $payment, PaymentIntent $intent): BillingPayment
    {
        $payment->fill([
            'status' => $intent->status,
            'redirect_url' => is_string($intent->provider_data['redirect_url'] ?? null) ? mb_substr($intent->provider_data['redirect_url'], 0, 500) : $payment->redirect_url,
            'message' => in_array($intent->status, ['failed', 'cancelled', 'timeout'], true) ? ($intent->result_message ?? __('billing.errors.payment_failed')) : null,
        ]);

        if ($payment->isDirty()) {
            $payment->save();
        }

        return $payment;
    }

    /** In the operator's context. */
    private function markOperatorPaid(OperatorInvoice $invoice, string $via, string $reference): OperatorInvoice
    {
        $invoice->fill([
            'status' => BillingInvoice::PAID,
            'paid_at' => CarbonImmutable::now(),
            'paid_via' => $via,
            'payment_reference' => mb_substr($reference, 0, 100),
        ])->save();

        // MKT-03: in the same transaction, the developers' share of its app lines.
        event(new OperatorInvoicePaid($invoice));

        return $invoice;
    }

    /** In the customer's context: the copy is paid, and the subscription active again (SUB-06). */
    private function copyPaid(OperatorInvoice $invoice): void
    {
        // Exactly once: only the update that finds the copy still open goes on to notify and audit.
        $changed = BillingInvoice::query()->where('operator_invoice_id', $invoice->id)->where('status', BillingInvoice::OPEN)
            ->update(['status' => BillingInvoice::PAID, 'paid_at' => $invoice->paid_at, 'updated_at' => CarbonImmutable::now()]);

        if ($changed === 0) {
            return;
        }

        $copy = BillingInvoice::query()->where('operator_invoice_id', $invoice->id)->firstOrFail();
        app(Auditor::class)->record('core.billing_invoice.paid', $copy, ['status' => BillingInvoice::OPEN], ['status' => BillingInvoice::PAID, 'paid_at' => $copy->paid_at?->toIso8601String()]);
        $subscription = Subscription::query()->first();

        if ($subscription !== null) {
            $overdue = in_array($subscription->status, [Subscription::PAST_DUE, Subscription::READ_ONLY], true);
            $subscription = $this->subscriptions->paid($subscription, $copy);

            // Reactivated after the period ended: the period restarts today (ADR 016).
            if ($overdue) {
                app(BillingCycle::class)->restartAfterReactivation($subscription, CarbonImmutable::now());
            }
        }

        $this->notices->send(BillingNotices::PAYMENT_RECEIVED, [
            'amount' => $this->notices->money($copy->total_minor, $copy->currency),
            'number' => $copy->number,
            'date' => BillingNotices::date($copy->paid_at),
        ]);
    }

    private function setupKey(string $reference): string
    {
        return 'billing-card-setup:'.$this->tenants->require().':'.hash('sha256', $reference);
    }

    /** "2547•••••678": the first four and last three digits. */
    public static function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return strlen($digits) <= 7 ? str_repeat('•', strlen($digits)) : substr($digits, 0, 4).str_repeat('•', strlen($digits) - 7).substr($digits, -3);
    }
}
