<?php

namespace App\Core\Billing\Http;

use App\Core\Billing\Models\Subscription;
use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * SUB-05: in read-only mode (an invoice unpaid past the grace period)
 * every write answers 402 `subscription_overdue` ("pay to continue").
 * Part of the `tenant` middleware group, so every tenant route passes it.
 *
 * Still allowed (classify()):
 * - reading (GET, HEAD, OPTIONS), list exports included (EXP-01);
 * - billing (`subscription*`): seeing and paying what is owed (SUB-06);
 * - signing out and finishing two-factor;
 * - the full data export (EXP-02, `tenant-exports`);
 * - the till's own sign-in and device security (PIN, overrides, secret);
 * - POS uploads (sales, shifts, voids, refunds, cash movements, number
 *   ranges, payments at the till) for the POS window after read-only
 *   starts (`pos_stops_at`); after it they answer 402
 *   `subscription_pos_stopped` and the till shows why.
 *
 * LIM-04: approvals are never blocked by usage limits; read-only is
 * dunning, not a limit, and approvals pause with every other write.
 */
class EnforceSubscription
{
    public const ALLOWED = 'allowed';

    public const BILLING = 'billing';

    public const ACCOUNT = 'account';

    public const EXPORT = 'export';

    public const DEVICE = 'device';

    public const POS_UPLOAD = 'pos_upload';

    public const BLOCKED = 'blocked';

    /** Writes allowed in read-only mode, "METHOD uri" (uri without the api/v1 prefix) => why. */
    public const ACCOUNT_WRITES = [
        'POST auth/sign-out' => self::ACCOUNT,
        'POST me/two-factor/totp' => self::ACCOUNT,
        'POST me/two-factor/totp/confirm' => self::ACCOUNT,
        'POST me/two-factor/sms' => self::ACCOUNT,
        'POST me/two-factor/sms/confirm' => self::ACCOUNT,
        'POST pos/pin/verify' => self::DEVICE,
        'POST pos/pin/attempts' => self::DEVICE,
        'POST pos/pin/change' => self::DEVICE,
        'POST pos/override' => self::DEVICE,
        'POST sync/device-secret/rotate' => self::DEVICE,
        'POST sync/device-secret/activate' => self::DEVICE,
        'POST tenant-exports' => self::EXPORT,
        'POST pos/number-ranges' => self::POS_UPLOAD,
        'POST pos/shifts' => self::POS_UPLOAD,
        'POST pos/sales' => self::POS_UPLOAD,
        'POST pos/cash-movements' => self::POS_UPLOAD,
        'POST pos/voids' => self::POS_UPLOAD,
        'POST pos/refunds' => self::POS_UPLOAD,
        'POST payments/intents' => self::POS_UPLOAD,
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $class = self::classify($request->method(), $request->route());

        if ($class === self::ALLOWED || $this->tenants->id() === null) {
            return $next($request);
        }

        $subscription = Subscription::query()->first(['id', 'status', 'pos_stops_at']);

        if ($subscription === null || ! $subscription->refusesWrites() || in_array($class, [self::BILLING, self::ACCOUNT, self::EXPORT, self::DEVICE], true)) {
            return $next($request);
        }

        if ($class === self::POS_UPLOAD) {
            if ($subscription->posAllowedAt(CarbonImmutable::now())) {
                return $next($request);
            }

            throw new ApiException(402, 'subscription_pos_stopped', __('billing.read_only.pos_stopped'));
        }

        throw new ApiException(402, 'subscription_overdue', __('billing.read_only.message'));
    }

    /** How a request to $route with $method is treated in read-only mode. */
    public static function classify(string $method, Route|string|null $route): string
    {
        if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return self::ALLOWED;
        }

        $uri = preg_replace('#^api/v1/#', '', ltrim($route instanceof Route ? $route->uri() : (string) $route, '/'));

        if ($uri === 'subscription' || str_starts_with($uri, 'subscription/')) {
            return self::BILLING;
        }

        return self::ACCOUNT_WRITES[strtoupper($method).' '.$uri] ?? self::BLOCKED;
    }
}
