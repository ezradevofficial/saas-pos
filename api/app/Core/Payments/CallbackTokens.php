<?php

namespace App\Core\Payments;

use App\Core\Audit\Auditor;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The unguessable token in a payment method's callback URLs (48 letters
 * and digits): the provider's callbacks carry no session, so the token is
 * what names the tenant and the method. Stored encrypted (to build the
 * URLs again) and as a sha256 hash (to find it). The tenant is found by
 * the security-definer function payment_tenant_for_callback_token (ADR
 * 002); the method is then read under that tenant's row-level security.
 * Rotating it is audited and makes the old URLs stop working (they must
 * be registered with the provider again).
 *
 * Callback paths never contain "mpesa", "safaricom", "sql" or "query":
 * Safaricom refuses C2B URLs with those words.
 */
class CallbackTokens
{
    public const LENGTH = 48;

    /** Callback kinds and what calls them. */
    public const KINDS = [
        'stk',             // STK push result
        'c2b-validate',    // C2B validation (when external validation is on)
        'c2b-confirm',     // C2B confirmation (money received)
        'b2c-result',      // B2C refund result
        'b2c-timeout',     // B2C queue time-out
        'status-result',   // transaction status result
        'status-timeout',  // transaction status queue time-out
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** The method's callback URL for $kind, creating its token the first time. */
    public function url(PaymentMethod $method, string $kind): string
    {
        return self::urlFor($this->token($method), $kind);
    }

    public static function urlFor(string $token, string $kind): string
    {
        return rtrim((string) config('payments.callback_base_url'), '/').'/api/v1/payments/callbacks/'.$token.'/'.$kind;
    }

    /** @return array<string, string> every callback URL of the method, by kind */
    public function urls(PaymentMethod $method): array
    {
        $token = $this->token($method);

        return collect(self::KINDS)->mapWithKeys(fn (string $kind) => [$kind => self::urlFor($token, $kind)])->all();
    }

    public function token(PaymentMethod $method): string
    {
        if (filled($method->callback_token)) {
            return (string) $method->callback_token;
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($method) {
            $fresh = PaymentMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            if (filled($fresh->callback_token)) {
                $method->setRawAttributes($fresh->getAttributes(), true);

                return (string) $fresh->callback_token;
            }

            return $this->store($method, rotated: false);
        });
    }

    /** A new token: the old URLs stop working at once. */
    public function rotate(PaymentMethod $method): string
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($method) {
            PaymentMethod::query()->whereKey($method->id)->lockForUpdate()->firstOrFail();

            return $this->store($method, rotated: true);
        });
    }

    /**
     * The method a callback token names, with its tenant entered; null for
     * an unknown or malformed token.
     */
    public function resolve(string $token): ?PaymentMethod
    {
        if (preg_match('/^[A-Za-z0-9]{'.self::LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $tenantId = DB::selectOne('select payment_tenant_for_callback_token(?) as tenant_id', [$hash])?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);

        $method = PaymentMethod::query()->where('callback_token_hash', $hash)->first();

        // Constant-time check of the stored token (the hash found the row).
        return $method !== null && hash_equals((string) $method->callback_token, $token) ? $method : null;
    }

    private function store(PaymentMethod $method, bool $rotated): string
    {
        $token = Str::random(self::LENGTH);

        // The token is a credential: no update entry with its value; the
        // rotation itself is audited by name only.
        $method->forceFill([
            'callback_token' => $token,
            'callback_token_hash' => hash('sha256', $token),
        ])->saveQuietly();

        if ($rotated) {
            $this->auditor->record('core.payment_method.callback_token_rotate', $method);
        }

        return $token;
    }
}
