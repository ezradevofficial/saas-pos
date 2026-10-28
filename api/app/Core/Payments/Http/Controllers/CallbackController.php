<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Audit\AuditContext;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Daraja\DarajaCallbacks;
use App\Core\Payments\Http\Requests\ProviderCallbackRequest;
use App\Core\Payments\Pawapay\PawapayCallbacks;
use App\Core\Payments\Pawapay\PawapaySignatures;
use App\Core\Payments\PaymentProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Payment provider callbacks (public, rate-limited). In order:
 *
 * 1. the caller's address must be one of the provider's (403 otherwise,
 *    `payments.mpesa.enforce_callback_ips`);
 * 2. the token must name a payment method (404 for an unknown or malformed
 *    token, with nothing about why); its tenant is entered;
 * 3. the method's adapter handles the kind (Daraja, and pawaPay's
 *    `pawapay-deposit` and `pawapay-refund`, whose signature is verified when
 *    enabled and whose body only names the payment: pawaPay is asked again),
 *    applying it once and answering what the provider expects.
 *
 * The system acts: audit entries name no user or device (AUD-02).
 */
class CallbackController
{
    public function __construct(
        private readonly CallbackTokens $tokens,
        private readonly PaymentProviderRegistry $registry,
        private readonly DarajaCallbacks $daraja,
        private readonly PawapayCallbacks $pawapay,
        private readonly PawapaySignatures $signatures,
        private readonly AuditContext $audit,
    ) {}

    public function __invoke(ProviderCallbackRequest $request, string $token, string $kind): JsonResponse
    {
        $pawapay = str_starts_with($kind, 'pawapay-');

        if (! $pawapay && config('payments.mpesa.enforce_callback_ips') && ! in_array($request->ip(), (array) config('payments.mpesa.callback_ips', []), true)) {
            Log::warning('Payment callback refused: address not allowed', ['ip' => $request->ip(), 'kind' => $kind]);
            abort(403);
        }

        $method = $this->tokens->resolve($token);
        abort_if($method === null || $this->registry->driverName($method) !== ($pawapay ? 'pawapay' : 'mpesa_daraja'), 404);

        $this->audit->reset();
        $this->audit->setIp($request->ip());

        if ($pawapay) {
            abort_if($this->signatures->enabled() && ! $this->signatures->valid($request), 401);

            $kind === 'pawapay-deposit' ? $this->pawapay->deposit($request->payload()) : $this->pawapay->refund($request->payload());

            return response()->json(['status' => 'ok']);
        }

        return response()->json($this->daraja->handle($method, $kind, $request->payload()));
    }
}
