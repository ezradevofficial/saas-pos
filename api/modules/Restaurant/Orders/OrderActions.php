<?php

namespace Modules\Restaurant\Orders;

use App\Core\Audit\Auditor;
use App\Core\Currency\Converter;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\Rbac\LimitRules;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Restaurant\Events\OrderClosed;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Resend;
use Modules\Restaurant\Models\Settlement;
use Modules\Restaurant\Models\Ticket;
use Modules\Restaurant\Support\Errors;

/**
 * REST-05, REST-11, REST-15, REST-16: what is done to an order after it is
 * opened, shared by the back office routes and the till's upload so both
 * obey one set of rules, and each change is audited (AUD-01).
 */
class OrderActions
{
    public function __construct(
        private readonly Auditor $auditor,
        private readonly Tickets $tickets,
        private readonly ScopeResolver $resolver,
        private readonly LimitRules $limits,
        private readonly Converter $converter,
    ) {}

    /** REST-05: moves the order to another waiter (a user who exists in the tenant). */
    public function transfer(Order $order, string $waiterId, ?User $by, bool $unauthorised = false): Order
    {
        $this->mustBeOpen($order);

        if (! User::query()->whereKey($waiterId)->exists()) {
            throw Errors::make(422, 'waiter_unknown', field: 'waiter_id');
        }

        if ($order->waiter_id === $waiterId) {
            return $order;
        }

        $before = $order->waiter_id;
        $order->waiter_id = $waiterId;
        $order->updated_by = $by?->id ?? $order->updated_by;
        $order->header_at = now();
        $order->rev++;
        $order->flags = $unauthorised ? $this->withFlag($order->flags, 'transfer_unauthorised') : $order->flags;
        $order->save();
        $this->auditor->record('restaurant.order.transfer', $order, ['waiter_id' => $before], ['waiter_id' => $waiterId], array_filter(['user_id' => $by?->id]));

        return $order;
    }

    /** REST-15: every line of $from moves to $into (same location, both open) and $from is merged. */
    public function merge(Order $from, Order $into, ?User $by, bool $unauthorised = false): Order
    {
        $this->mustBeOpen($from);
        $this->mustBeOpen($into);

        if ($from->id === $into->id || $from->location_id !== $into->location_id) {
            throw Errors::make(422, 'merge_invalid', field: 'into_order_id');
        }

        // REST-15: money already taken stays with the order it was taken for.
        if (Settlement::query()->where('order_id', $from->id)->whereNull('voided_at')->exists()) {
            throw Errors::make(409, 'order_has_payments');
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($from, $into, $by, $unauthorised) {
            $moved = OrderLine::query()->where('order_id', $from->id)->update(['order_id' => $into->id]);
            Ticket::query()->where('order_id', $from->id)->update(['order_id' => $into->id]);
            Resend::query()->where('order_id', $from->id)->update(['order_id' => $into->id]);
            $from->status = 'merged';
            $from->merged_into_id = $into->id;
            $from->closed_at = now();
            $from->flags = $unauthorised ? $this->withFlag($from->flags, 'merge_unauthorised') : $from->flags;
            $from->rev++;
            $from->save();
            $into->covers = (int) $into->covers + (int) $from->covers;
            $into->header_at = now();
            $into->rev++;
            $into->save();
            $this->auditor->record('restaurant.order.merge', $from, null, ['into_order_id' => $into->id, 'lines' => $moved], array_filter(['user_id' => $by?->id]));
        });

        return $into;
    }

    /**
     * REST-11: whether $user may void a line worth $payableMinor after it was sent:
     * `pos.sale.void` at the location, within their `max_refund_amount` (RBAC-06); an Owner has no limit.
     *
     * @return null|string null when allowed, else the error code
     */
    public function voidRefusal(User $user, Order $order, string $payableMinor): ?string
    {
        $scope = Scope::location($order->location_id);

        if (! $user->isActive() || ! $this->resolver->can($user, 'pos.sale.void', $scope)) {
            return 'void_not_permitted';
        }

        return $this->withinVoidLimit($user, $scope, $this->baseMajor($order, $payableMinor)) ? null : 'void_limit_exceeded';
    }

    /**
     * RBAC-06, CUR-04: $minor of the order's currency in the company's base currency, in major units, at the
     * current rate; null when there is no rate (then only an Owner, or a manager approving, can void).
     */
    public function baseMajor(Order $order, string $minor): ?BigDecimal
    {
        $company = Company::query()->find($order->company_id);

        if ($company === null) {
            return null;
        }

        try {
            $base = $this->converter->toBase(Money::ofMinor($minor, $order->currency), $company)['base'];
        } catch (RateUnavailable) {
            return null;
        }

        return BigDecimal::ofUnscaledValue((string) $base->minor(), app(CurrencyDecimals::class)->for($company->base_currency));
    }

    /** RBAC-06: $base is within $user's `max_refund_amount`; an Owner has no limit; without a base amount (no rate) only an Owner passes. */
    public function withinVoidLimit(User $user, Scope $scope, ?BigDecimal $base): bool
    {
        $roles = $this->resolver->roleIds($user, $scope);

        if ($roles !== [] && Role::query()->whereKey($roles)->where('is_owner', true)->exists()) {
            return true;
        }

        $max = $this->limits->max($user, 'max_refund_amount', $scope);

        return $base !== null && $max !== null && $base->isLessThanOrEqualTo($max);
    }

    /** An amount in minor units as major units of its currency, the unit RBAC-06 limits are set in (the order's currency, not converted). */
    public function major(string $minor, string $currency): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($minor, app(CurrencyDecimals::class)->for($currency));
    }

    /**
     * REST-06, REST-11: voids a line. Unsent lines go freely; a sent line needs the right (see voidRefusal),
     * and the station is told with a void ticket.
     *
     * @param  list<string>  $flags  review flags to keep on the line
     */
    public function voidLine(Order $order, OrderLine $line, string $reason, ?User $by, ?string $approvedBy = null, array $flags = [], ?CarbonInterface $at = null, bool $offline = false): OrderLine
    {
        $this->mustBeOpen($order);

        if ($line->voided_at !== null) {
            return $line;
        }

        $sent = $line->isSent();
        $line->voided_at = $at ?? now();
        $line->voided_by = $by?->id;
        $line->void_approved_by = $approvedBy;
        $line->void_reason = $reason;
        $line->void_after_send = $sent;
        $line->pending_void = null;
        $merged = $line->flags ?? [];

        foreach ($flags as $code) {
            $merged = $this->withFlag($merged, $code);
        }

        $line->flags = $merged;
        $line->save();
        $order->rev++;
        $order->save();

        if (! $sent) {
            // AUD-01: removing an unsent line is free, but not unrecorded.
            $this->auditor->record('restaurant.order.line_removed', $order, null, [
                'line_id' => $line->id, 'item_id' => $line->item_id, 'name' => $line->name, 'qty' => (string) $line->qty, 'reason' => $reason,
            ], array_filter(['user_id' => $by?->id]));
        }

        if ($sent) {
            $this->tickets->make($order, collect([$line]), 'void', $offline, $by?->id);
            $this->auditor->record('restaurant.order.void_sent', $order, null, [
                'line_id' => $line->id, 'item_id' => $line->item_id, 'name' => $line->name, 'qty' => (string) $line->qty,
                'reason' => $reason, 'approved_by' => $approvedBy, 'flags' => $flags,
            ], array_filter(['user_id' => $by?->id, 'on_behalf_of_user_id' => $approvedBy]));
        }

        return $line;
    }

    /**
     * REST-11: a void of a sent line asked for by someone the server cannot prove (an unverified sign-in or
     * override): the line stays on the bill, flagged, until a reviewer approves it (the void route) or rejects it.
     *
     * @param  list<string>  $flags
     */
    public function requestVoid(Order $order, OrderLine $line, string $reason, User $by, ?string $approver, array $flags, CarbonInterface $at): OrderLine
    {
        $this->mustBeOpen($order);

        if ($line->voided_at !== null || $line->pending_void !== null) {
            return $line;
        }

        $line->pending_void = ['at' => $at->toIso8601String(), 'by' => $by->id, 'reason' => $reason, 'approver_id' => $approver, 'flags' => $flags];
        $merged = $line->flags ?? [];

        foreach ([...$flags, 'void_pending'] as $code) {
            $merged = $this->withFlag($merged, $code);
        }

        $line->flags = $merged;
        $line->save();
        $order->rev++;
        $order->save();
        $this->auditor->record('restaurant.order.void_requested', $order, null, [
            'line_id' => $line->id, 'name' => $line->name, 'reason' => $reason, 'named_approver' => $approver, 'flags' => $flags,
        ], array_filter(['user_id' => $by->id]));

        return $line;
    }

    /** REST-11: a reviewer turns a pending void down; the line stays as it was sold. */
    public function rejectVoid(Order $order, OrderLine $line, ?User $by): OrderLine
    {
        $this->mustBeOpen($order);

        if ($line->pending_void === null) {
            throw Errors::make(409, 'no_pending_void');
        }

        $line->pending_void = null;
        $line->flags = array_values(array_filter($line->flags ?? [], fn ($f) => ($f['code'] ?? $f) !== 'void_pending'));
        $line->save();
        $this->auditor->record('restaurant.order.void_rejected', $order, null, ['line_id' => $line->id], array_filter(['user_id' => $by?->id]));

        return $line;
    }

    /** An order with nothing sent and nothing paid can be cancelled. */
    public function cancel(Order $order, string $reason, ?User $by): Order
    {
        $this->mustBeOpen($order);

        $sent = OrderLine::query()->where('order_id', $order->id)->whereNotNull('sent_at')->exists();
        $paid = Settlement::query()->where('order_id', $order->id)->whereNull('voided_at')->exists();

        if ($sent || $paid) {
            throw Errors::make(409, 'order_not_empty');
        }

        $order->status = 'cancelled';
        $order->closed_at = now();
        $order->rev++;
        $order->save();
        $this->auditor->record('restaurant.order.cancel', $order, null, ['reason' => $reason], array_filter(['user_id' => $by?->id]));

        return $order;
    }

    /** REST-16: the order is paid in full. */
    public function close(Order $order, CarbonInterface $at): void
    {
        if ($order->status !== 'open') {
            return;
        }

        $order->status = 'closed';
        $order->closed_at = $at;
        $order->rev++;
        $order->save();
        $this->auditor->record('restaurant.order.close', $order, null, ['closed_at' => $at->toIso8601String()]);
        OrderClosed::dispatch((string) $order->tenant_id, $order->id, $order->location_id);
    }

    /** REST-16: a voided sale reopens an order that has no live settlement left. */
    public function reopen(Order $order): void
    {
        if ($order->status !== 'closed') {
            return;
        }

        $order->status = 'open';
        $order->closed_at = null;
        $order->rev++;
        $order->save();
        $this->auditor->record('restaurant.order.reopen', $order, null, null);
    }

    /** @param list<array{code: string}>|list<string>|null $flags */
    public function withFlag(?array $flags, string $code): array
    {
        $flags ??= [];

        foreach ($flags as $flag) {
            if (($flag['code'] ?? $flag) === $code) {
                return $flags;
            }
        }

        return [...$flags, ['code' => $code]];
    }

    private function mustBeOpen(Order $order): void
    {
        if ($order->status !== 'open') {
            throw Errors::make(409, 'order_closed');
        }
    }
}
