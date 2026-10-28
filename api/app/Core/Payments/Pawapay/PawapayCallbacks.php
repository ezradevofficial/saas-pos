<?php

namespace App\Core\Payments\Pawapay;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\PaymentIntents;
use App\Core\Payments\PaymentProviderRegistry;
use App\Core\Payments\ProviderUnavailable;
use Illuminate\Support\Str;

/**
 * pawaPay callbacks (deposits, refunds), in the tenant's context. A
 * callback only names a deposit or refund id; the answer is always asked
 * of pawaPay's API again, and only that answer is applied (a forged or
 * wrong callback body can not mark anything paid). Idempotent: ids are
 * ours (derived from the intent id), and a final status never changes.
 */
class PawapayCallbacks
{
    public function __construct(
        private readonly PaymentIntents $intents,
        private readonly PaymentProviderRegistry $registry,
    ) {}

    /** The sale intent whose deposit the callback names, after the re-check; null for an unknown id. */
    public function deposit(array $body): ?PaymentIntent
    {
        $intent = $this->find($body['depositId'] ?? null, 'sale');

        return $intent === null ? null : $this->intents->recheck($intent);
    }

    /** The refund intent whose refund the callback names, after the re-check; null for an unknown id. */
    public function refund(array $body): ?PaymentIntent
    {
        $intent = $this->find($body['refundId'] ?? null, 'refund');

        if ($intent === null || ! in_array($intent->status, ['pending', 'unknown', 'timeout'], true)) {
            return $intent;
        }

        $method = PaymentMethod::query()->findOrFail($intent->payment_method_id);
        $provider = $this->registry->driver('pawapay');

        try {
            $result = $provider instanceof PawapayProvider ? $provider->refundResult($intent, $method) : null;
        } catch (ProviderUnavailable) {
            return $intent;
        }

        return $result === null || $result->isPending() ? $intent : $this->intents->apply($intent, $result);
    }

    private function find(mixed $id, string $purpose): ?PaymentIntent
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return PaymentIntent::query()->where('driver', 'pawapay')->where('purpose', $purpose)->where('provider_request_id', strtolower($id))->first();
    }
}
