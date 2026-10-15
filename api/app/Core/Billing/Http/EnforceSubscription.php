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
 * - approvals (LIM-04 is absolute: approving, rejecting, returning,
 *   commenting, asking for information, attaching and reassigning, the
 *   bulk approve, and the POS's held voids, refunds and cash movements);
 *   payroll runs get the same treatment (PAY-28: calculating, submitting
 *   and posting a run, so staff are paid);
 * - the user's own account: signing out (here or everywhere), revoking a
 *   session, two-factor and their own POS PIN;
 * - the full data export (EXP-02, `tenant-exports`), its download link included;
 * - the till's own sign-in and device security (PIN, overrides, secret);
 * - POS uploads (sales, shifts, voids, refunds, cash movements, number
 *   ranges, payments at the till) for the POS window after read-only
 *   starts (`pos_stops_at`); after it they answer 402
 *   `subscription_pos_stopped` and the till shows why.
 *
 * Everything else is refused, including new records and settings.
 */
class EnforceSubscription
{
    public const ALLOWED = 'allowed';

    public const BILLING = 'billing';

    public const APPROVAL = 'approval';

    public const ACCOUNT = 'account';

    public const EXPORT = 'export';

    public const DEVICE = 'device';

    public const POS_UPLOAD = 'pos_upload';

    public const BLOCKED = 'blocked';

    /** Writes allowed in read-only mode, "METHOD uri" (uri without the api/v1 prefix) => why. */
    public const ACCOUNT_WRITES = [
        'POST auth/sign-out' => self::ACCOUNT,
        'DELETE auth/sessions/{id}' => self::ACCOUNT,
        'POST users/{user}/sign-out-everywhere' => self::ACCOUNT,
        'DELETE me/two-factor' => self::ACCOUNT,
        'PUT me/pos-pin' => self::ACCOUNT,
        'DELETE me/pos-pin' => self::ACCOUNT,
        // LIM-04: approvals are never blocked, by limits or by dunning.
        'POST approvals/bulk-approve' => self::APPROVAL,
        'POST approvals/{approval}/approve' => self::APPROVAL,
        'POST approvals/{approval}/reject' => self::APPROVAL,
        'POST approvals/{approval}/return' => self::APPROVAL,
        'POST approvals/{approval}/comment' => self::APPROVAL,
        'POST approvals/{approval}/request-info' => self::APPROVAL,
        'POST approvals/{approval}/attachments' => self::APPROVAL,
        'POST approvals/{approval}/reassign' => self::APPROVAL,
        'POST pos/voids/{pos_void}/approve' => self::APPROVAL,
        'POST pos/voids/{pos_void}/reject' => self::APPROVAL,
        'POST pos/refunds/{pos_refund}/approve' => self::APPROVAL,
        'POST pos/refunds/{pos_refund}/reject' => self::APPROVAL,
        'POST pos/cash-movements/{pos_cash_movement}/approve' => self::APPROVAL,
        'POST pos/cash-movements/{pos_cash_movement}/reject' => self::APPROVAL,
        // PAY-28: payroll runs are never blocked (LIM-04), by limits or by dunning.
        'POST payroll/runs/{payroll_run}/calculate' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/submit' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/post' => self::APPROVAL,
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
        'POST tenant-exports/{tenant_export}/link' => self::EXPORT,
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

        if ($subscription === null || ! $subscription->refusesWrites() || in_array($class, [self::BILLING, self::APPROVAL, self::ACCOUNT, self::EXPORT, self::DEVICE], true)) {
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
