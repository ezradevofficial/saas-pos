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
use Throwable;

/**
 * MD-01, WF-01, APR-01: credit limit changes through a flow.
 *
 * - request(): the request is saved as pending with the party's current
 *   limit as a snapshot, numbered, and its flow started, in one
 *   transaction (no flow, no request). One open request per party.
 * - completed(): its flow ended. `approved` writes the requested limit to
 *   the party and marks the request applied; any other outcome rejects it.
 *   The party change is audited as `core.party.credit_limit_apply` by the
 *   system on behalf of the decider, with the request's number, so the
 *   party's history reads "Credit limit changed via CLC-000123" (MD-07).
 * - cancelled(): its flow was cancelled; the party is untouched.
 *
 * Direct edits (PartyRules): lowering an existing limit in its currency
 * needs only `core.party.edit`; anything else (a raise, a first limit,
 * removing the limit, another currency) also needs
 * `core.credit_limit.set_directly` (Owner, Admin), else a request.
 */
class CreditLimitChanges
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly CreditLimitChangeNumbers $numbers,
        private readonly Auditor $auditor,
        private readonly ScopeResolver $resolver,
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
     * (null: an automatic decision). Runs in the request's tenant.
     */
    public function completed(string $changeId, string $outcome, ?string $userId): void
    {
        try {
            $this->transaction(function () use ($changeId, $outcome, $userId) {
                $change = CreditLimitChange::query()->whereKey($changeId)->lockForUpdate()->first();

                if ($change === null || $change->status !== CreditLimitChange::PENDING) {
                    return;
                }

                $now = CarbonImmutable::now();

                if ($outcome !== 'approved') {
                    $change->fill(['status' => CreditLimitChange::REJECTED, 'decided_by' => $userId, 'decided_at' => $now])->save();

                    return;
                }

                $this->apply($change, $userId);
                $change->fill(['status' => CreditLimitChange::APPLIED, 'decided_by' => $userId, 'decided_at' => $now, 'applied_at' => $now])->save();
            });
        } catch (Throwable $e) {
            // The decision is already committed: keep it visible as approved, not applied.
            report($e);

            if ($outcome === 'approved') {
                $this->transaction(fn () => CreditLimitChange::query()->whereKey($changeId)->where('status', CreditLimitChange::PENDING)->first()
                    ?->fill(['status' => CreditLimitChange::APPROVED, 'decided_by' => $userId, 'decided_at' => CarbonImmutable::now()])->save());
            }
        }
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
     * Whether changing a limit from $current to $new needs set_directly:
     * everything but keeping it or lowering it in its currency.
     */
    public static function needsApproval(?Money $current, ?Money $new): bool
    {
        if ($current === null && $new === null) {
            return false;
        }

        if ($current === null || $new === null || $current->currency() !== $new->currency()) {
            return true;
        }

        // A raise; the same amount or less is not.
        return ! $new->minus($current)->isNegative() && ! $new->equals($current);
    }

    public static function needsRequestError(): ApiException
    {
        $message = __('core.credit_limit_change.errors.needs_request');

        return new ApiException(422, 'credit_limit_needs_request', $message, ['credit_limit' => [$message]]);
    }

    private function apply(CreditLimitChange $change, ?string $userId): void
    {
        $party = Party::query()->whereKey($change->party_id)->lockForUpdate()->firstOrFail();
        $keys = ['credit_limit_minor', 'credit_limit_currency'];
        $before = array_combine($keys, array_map(fn (string $key) => $party->getAttribute($key), $keys));

        $party->forceFill([
            'credit_limit_minor' => $change->requested_limit_minor,
            'credit_limit_currency' => $change->requested_limit_currency,
        ]);
        // Audited below as the system acting for the decider, with the request's number.
        $party->saveQuietly();
        $party->refresh();

        $this->auditor->record('core.party.credit_limit_apply', $party, $before, [
            ...array_combine($keys, array_map(fn (string $key) => $party->getAttribute($key), $keys)),
            'credit_limit_change' => $change->number,
        ], ['user_id' => null, 'on_behalf_of_user_id' => $userId]);
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
