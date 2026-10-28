<?php

namespace App\Core\Payments\Pawapay;

use App\Core\Billing\Operator\Operator;
use App\Core\MasterData\PaymentMethods\PaymentMethod;

/**
 * The pawaPay API token of a payment method (MD-04: an encrypted secret,
 * never returned). The platform operator's methods may fall back to
 * PAWAPAY_API_TOKEN; a business never does. Never logged or serialised.
 */
final class PawapayCredentials
{
    private function __construct(public readonly string $token, public readonly string $baseUrl) {}

    public static function of(PaymentMethod $method): self
    {
        $token = (string) (((array) ($method->secrets ?? []))['api_token'] ?? '');

        if ($token === '' && app(Operator::class)->is((string) $method->tenant_id)) {
            $token = (string) config('payments.pawapay.api_token', '');
        }

        return new self($token, (string) config('payments.pawapay.base_url'));
    }

    /** The operator's token alone (callbacks that name no method). */
    public static function operator(): self
    {
        return new self((string) config('payments.pawapay.api_token', ''), (string) config('payments.pawapay.base_url'));
    }

    public function isSet(): bool
    {
        return $this->token !== '';
    }
}
