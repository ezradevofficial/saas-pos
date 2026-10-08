<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentProviders;
use App\Core\Payments\CallbackTokens;
use App\Core\Payments\Daraja\MpesaDarajaProvider;
use App\Core\Payments\Http\Requests\PaymentMethodCallbacksRequest;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Payments\ProviderUnavailable;
use Illuminate\Http\JsonResponse;

/**
 * A payment method's callback URLs (`core.payment_method.configure`):
 * shown so they can be registered with the provider, rotated when they
 * may have leaked, and the C2B URLs registered with Daraja for the
 * method's Till or Paybill.
 */
class PaymentMethodCallbackController
{
    public function __construct(
        private readonly CallbackTokens $tokens,
        private readonly PaymentProviderRegistry $registry,
        private readonly Auditor $auditor,
        private readonly PaymentProviders $providers,
    ) {}

    public function show(PaymentMethodCallbacksRequest $request): JsonResponse
    {
        return response()->json(['data' => ['urls' => $this->tokens->urls($request->target())]]);
    }

    public function rotate(PaymentMethodCallbacksRequest $request): JsonResponse
    {
        $method = $request->target();
        $this->tokens->rotate($method);

        return response()->json(['data' => ['urls' => $this->tokens->urls($method)]]);
    }

    public function registerC2b(PaymentMethodCallbacksRequest $request): JsonResponse
    {
        $method = $request->target();
        $provider = $this->registry->for($method);

        if (! $provider instanceof MpesaDarajaProvider) {
            throw new ApiException(422, 'register_unsupported', __('payments.errors.register_unsupported'));
        }

        if ($this->providers->missing($method) !== []) {
            throw new ApiException(422, 'provider_not_configured', __('payments.errors.not_configured'));
        }

        try {
            $answer = $provider->client($method)->registerC2bUrls($this->tokens->url($method, 'c2b-validate'), $this->tokens->url($method, 'c2b-confirm'));
        } catch (ProviderUnavailable) {
            throw new ApiException(503, 'provider_unavailable', __('payments.errors.unreachable'));
        }

        $ok = (string) ($answer['ResponseCode'] ?? '') === '0' || str_contains(strtolower((string) ($answer['ResponseDescription'] ?? '')), 'success');

        if (! $ok) {
            throw new ApiException(422, 'register_failed', __('payments.errors.register_failed'));
        }

        $this->auditor->record('core.payment_method.c2b_register', $method);

        return response()->json(['data' => ['registered' => true]]);
    }
}
