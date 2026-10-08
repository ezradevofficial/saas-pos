<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Audit\Auditor;
use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * MD-01, WF-01, APR-01: credit limit changes through a flow. A party
 * without a limit (null) has no limit: it may buy on credit without bound.
 *
 * - request(): the request is saved as pending with the party's current
 *   limit as a snapshot, numbered, and its flow started, in one
 *   transaction (no flow, no request). One open request per party.
 * - completed(): its flow ended. `approved` marks the request approved and
 *   queues ApplyCreditLimitChange (retried, then the set_directly holders
 *   at its company are told); any other outcome rejects it.
 * - apply(): writes the requested limit to the party, once: only an
 *   approved request is applied, under a lock, and the party's limit is
 *   read again (a limit now in another currency is a conflict, not
 *   overwritten). Audited as `core.party.credit_limit_apply` by the system
 *   on behalf of the decider, with the request's number, so the party's
 *   history reads "Credit limit changed via CLC-000123" (MD-07).
 * - cancelled(): its flow was cancelled; the party is untouched.
 *
 * Direct edits (PartyRules): lowering a limit in its currency, or setting
 * a first limit on a party without one (less risk), needs only
 * `core.party.edit`; raising it, removing it (no limit) or changing its
 * currency also needs `core.credit_limit.set_directly` (Owner, Admin),
 * else a request.
 */
class CreditLimitChanges
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly CreditLimitChangeNumbers $numbers,
        private readonly Auditor $auditor,
        private readonly ScopeResolver $resolver,
        private readonly TenantContext $tenants,
    ) {}

    /** Save the request and start its flow (the caller checked permissions and the input). */
    public function request(Party $party, string $companyId, Money $requested, string $reason, User $by): CreditLimitChange
    {
        try {
            return $this->transaction(function () use ($party, $companyId, $requested, $reason, $by) {
                // The snapshot and the open-request check see the party as it is now.
                $party = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();

                if (CreditLimitChange::query()->where('party_id', $party->id)->whereIn('status', CreditLimitChange::OPEN)->exists()) {
                    throw self::openRequest();
                }

                $current = $party->creditLimit();
                self::assertRequestable($current, $requested);
                $number = $this->numbers->next();

                $change = CreditLimitChange::create([
                    ...$number,
                    'party_id' => $party->id,
                    'company_id' => $companyId,
                    'current_limit_minor' => $current?->minor(),
                    'current_limit_currency' => $current?->currency(),
                    'requested_limit_minor' => $requested->minor(),
                    'requested_limit_currency' => $requested->currency(),
                    'reason' => $reason,
                    'status' => CreditLimitChange::PENDING,
                    'requested_by' => $by->id,
                ]);

                $this->engine->start(CreditLimitChangeType::KEY, $change->id, $by);

                return $change->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Another request for the party was saved at the same moment.
            throw self::openRequest();
        }
    }

    /**
     * The requested limit against the party's current one: in the same
     * currency when the party has a limit, and different from it.
     */
    public static function assertRequestable(?Money $current, Money $requested): void
    {
        if ($current !== null && $current->currency() !== $requested->currency()) {
            $message = __('core.credit_limit_change.errors.currency', ['currency' => $current->currency()]);

            throw new ApiException(422, 'credit_limit_currency', $message, ['requested_limit.currency' => [$message]]);
        }

        if ($current !== null && $current->equals($requested)) {
            $message = __('core.credit_limit_change.errors.unchanged');

            throw new ApiException(422, 'credit_limit_unchanged', $message, ['requested_limit.amount_minor' => [$message]]);
        }
    }

    /**
     * WF-10: the request's flow ended with $outcome, decided by $userId
     * (null: an automatic decision). Runs in the request's tenant; an
     * approval queues the party write (after this commits).
     */
    public function completed(string $changeId, string $outcome, ?string $userId): void
    {
        $approved = $this->transaction(function () use ($changeId, $outcome, $userId) {
            $change = CreditLimitChange::query()->whereKey($changeId)->lockForUpdate()->first();

            if ($change === null || $change->status !== CreditLimitChange::PENDING) {
                return false;
            }

            $status = $outcome === 'approved' ? CreditLimitChange::APPROVED : CreditLimitChange::REJECTED;
            $change->fill(['status' => $status, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save();

            return $status === CreditLimitChange::APPROVED;
        });

        if ($approved) {
            ApplyCreditLimitChange::dispatch($this->tenants->require(), $changeId);
        }
    }

    /**
     * Write an approved request's limit to the party and mark it applied.
     * Idempotent: a request that is not (or no longer) approved is left
     * alone and false returned. A party whose limit is now in another
     * currency than the request's is a conflict (CreditLimitConflict).
     */
    public function apply(string $changeId): bool
    {
        return $this->transaction(function () use ($changeId) {
            $change = CreditLimitChange::query()->whereKey($changeId)->lockForUpdate()->first();

            if ($change === null || $change->status !== CreditLimitChange::APPROVED) {
                return false;
            }

            $party = Party::query()->whereKey($change->party_id)->lockForUpdate()->firstOrFail();
            $current = $party->creditLimit();

            if ($current !== null && $current->currency() !== $change->requested_limit_currency) {
                throw CreditLimitConflict::currency($change, $current->currency());
            }

            $keys = ['credit_limit_minor', 'credit_limit_currency'];
            $values = fn () => array_combine($keys, array_map(fn (string $key) => $party->getAttribute($key), $keys));
            $before = $values();

            $party->forceFill([
                'credit_limit_minor' => $change->requested_limit_minor,
                'credit_limit_currency' => $change->requested_limit_currency,
            ]);
            // Audited below as the system acting for the decider, with the request's number.
            $party->saveQuietly();
            $party->refresh();

            $this->auditor->record('core.party.credit_limit_apply', $party, $before, [
                ...$values(), 'credit_limit_change' => $change->number,
            ], ['user_id' => null, 'on_behalf_of_user_id' => $change->decided_by]);

            $change->fill(['status' => CreditLimitChange::APPLIED, 'applied_at' => CarbonImmutable::now()])->save();

            return true;
        });
    }

    /** WF-11: the request's flow was cancelled; the party is untouched. */
    public function cancelled(string $changeId): void
    {
        $this->transaction(function () use ($changeId) {
            $change = CreditLimitChange::query()->whereKey($changeId)->lockForUpdate()->first();

            if ($change !== null && $change->isOpen()) {
                $change->fill(['status' => CreditLimitChange::CANCELLED, 'cancelled_at' => CarbonImmutable::now()])->save();
            }
        });
    }

    /**
     * WF-11: cancel a pending request's flow, by its requester or a holder
     * of the request permission at its company. The engine records the
     * person when it would let them cancel themselves; a requester it would
     * not (a branch user, whose role does not cover the company) cancels as
     * the module, and the request's audit entry names them.
     */
    public function cancel(CreditLimitChange $change, User $by, string $reason): void
    {
        $workflow = $this->engine->current(CreditLimitChangeType::KEY, $change->id);

        if (! $change->isOpen() || $workflow === null || $workflow->status !== DocumentWorkflow::RUNNING) {
            $message = __('core.credit_limit_change.errors.not_open');

            throw new ApiException(422, 'credit_limit_change_not_open', $message);
        }

        $actor = $this->resolver->can($by, CreditLimitChangeType::REQUEST, Scope::company($change->company_id)) ? $by : null;
        $this->engine->cancel($workflow, $actor, $reason);
    }

    /** Whether $user may set a limit on a party of $companyId (null: shared) without a request. */
    public function canSetDirectly(User $user, ?string $companyId): bool
    {
        return $companyId === null
            ? $this->resolver->can($user, CreditLimitChangeType::SET_DIRECTLY)
            : $this->resolver->can($user, CreditLimitChangeType::SET_DIRECTLY, Scope::company($companyId));
    }

    /**
     * Whether changing a limit from $current to $new needs set_directly
     * (null: no limit): raising it, removing it or changing its currency.
     * Keeping it, lowering it, or setting a first limit does not.
     */
    public static function needsApproval(?Money $current, ?Money $new): bool
    {
        if ($current === null) {
            return false;
        }

        if ($new === null || $current->currency() !== $new->currency()) {
            return true;
        }

        return ! $new->minus($current)->isNegative() && ! $new->equals($current);
    }

    public static function needsRequestError(): ApiException
    {
        $message = __('core.credit_limit_change.errors.needs_request');

        return new ApiException(422, 'credit_limit_needs_request', $message, ['credit_limit' => [$message]]);
    }

    private static function openRequest(): ApiException
    {
        $message = __('core.credit_limit_change.errors.open');

        return new ApiException(422, 'credit_limit_change_open', $message);
    }

    private function transaction(callable $fn): mixed
    {
        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
