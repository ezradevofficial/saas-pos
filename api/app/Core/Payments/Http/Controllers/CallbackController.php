<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Audit\AuditContext;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Daraja\DarajaCallbacks;
use App\Core\Payments\Http\Requests\ProviderCallbackRequest;
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
 * 3. the method's adapter handles the kind (only Daraja has callbacks
 *    today), applying it once and answering what the provider expects.
 *
 * The system acts: audit entries name no user or device (AUD-02).
 */
class CallbackController
{
    public function __construct(
        private readonly CallbackTokens $tokens,
        private readonly PaymentProviderRegistry $registry,
        private readonly DarajaCallbacks $daraja,
        private readonly AuditContext $audit,
    ) {}

    public function __invoke(ProviderCallbackRequest $request, string $token, string $kind): JsonResponse
    {
        if (config('payments.mpesa.enforce_callback_ips') && ! in_array($request->ip(), (array) config('payments.mpesa.callback_ips', []), true)) {
            Log::warning('Payment callback refused: address not allowed', ['ip' => $request->ip(), 'kind' => $kind]);
            abort(403);
        }

        $method = $this->tokens->resolve($token);
        abort_if($method === null || $this->registry->driverName($method) !== 'mpesa_daraja', 404);

        $this->audit->reset();
        $this->audit->setIp($request->ip());

        return response()->json($this->daraja->handle($method, $kind, $request->payload()));
    }
}
