<?php

namespace App\Core\Billing\Http;

use App\Core\Billing\Models\Subscription;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Tenant;
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
 *   payroll runs get the same treatment (PAY-28: creating, calculating,
 *   submitting, posting and correcting a run, so staff are paid);
 * - what paying staff and filing returns needs (`staff`, stage 7C closing
 *   review): closing attendance periods, approving timesheets, clocking
 *   in on the web, cancelling a run and generating statutory returns;
 * - the user's own account: signing out (here or everywhere), revoking a
 *   session, two-factor and their own POS PIN;
 * - the full data export (EXP-02, `tenant-exports`), its download link included;
 * - the till's own sign-in and device security (PIN, overrides, secret);
 * - POS uploads (sales, shifts, voids, refunds, cash movements, number
 *   ranges, payments at the till, and staff clock-ins from tills and
 *   attendance devices) for the POS window after read-only
 *   starts (`pos_stops_at`); after it they answer 402
 *   `subscription_pos_stopped` and the till shows why.
 *
 * Everything else is refused, including new records and settings.
 *
 * ADM-01c: a tenant suspended by platform staff answers 403
 * `tenant_suspended` to every write except billing, the user's own
 * account and the data export.
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

    public const STAFF = 'staff';

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
        'POST payroll/runs' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/calculate' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/corrections' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/submit' => self::APPROVAL,
        'POST payroll/runs/{payroll_run}/post' => self::APPROVAL,
        // Stage 7C closing review: staff are paid and returns filed in read-only mode.
        'POST payroll/runs/{payroll_run}/cancel' => self::STAFF,
        'POST payroll/returns' => self::STAFF,
        'POST hr/attendance-periods/{hr_attendance_period}/close' => self::STAFF,
        'POST hr/timesheets/approve' => self::STAFF,
        'POST hr/me/clock' => self::STAFF,
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
        // RBAC-13: deciding on support access, and a support session ending itself
        // (support may be what an unpaid or suspended tenant needs).
        'POST support-access/{support_access_grant}/approve' => self::ACCOUNT,
        'POST support-access/{support_access_grant}/deny' => self::ACCOUNT,
        'POST support-access/{support_access_grant}/revoke' => self::ACCOUNT,
        'POST support-session/end' => self::ACCOUNT,
        'POST tenant-exports' => self::EXPORT,
        'POST tenant-exports/{tenant_export}/link' => self::EXPORT,
        'POST pos/number-ranges' => self::POS_UPLOAD,
        'POST pos/shifts' => self::POS_UPLOAD,
        'POST pos/sales' => self::POS_UPLOAD,
        'POST pos/cash-movements' => self::POS_UPLOAD,
        'POST pos/voids' => self::POS_UPLOAD,
        'POST pos/refunds' => self::POS_UPLOAD,
        'POST payments/intents' => self::POS_UPLOAD,
        // HR-22 to HR-24: clock-ins uploaded by tills and attendance devices.
        'POST hr/till/punches' => self::POS_UPLOAD,
        'POST hr/till/clock-attempts' => self::POS_UPLOAD,
        'POST hr/device/punches' => self::POS_UPLOAD,
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $class = self::classify($request->method(), $request->route());

        if ($class === self::ALLOWED || $this->tenants->id() === null) {
            return $next($request);
        }

        $subscription = Subscription::query()->first(['id', 'status', 'pos_stops_at']);

        // ADM-01c: a tenant suspended by platform staff keeps only its billing, its own
        // account and the data export; everything else (tills included) is refused.
        if ($subscription?->status === Subscription::SUSPENDED || ($subscription === null && Tenant::query()->whereKey($this->tenants->id())->value('status') === 'suspended')) {
            if (in_array($class, [self::BILLING, self::ACCOUNT, self::EXPORT], true)) {
                return $next($request);
            }

            throw new ApiException(403, 'tenant_suspended', __('platform.tenants.suspended'));
        }

        if ($subscription === null || ! $subscription->refusesWrites() || in_array($class, [self::BILLING, self::APPROVAL, self::ACCOUNT, self::EXPORT, self::DEVICE, self::STAFF], true)) {
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
