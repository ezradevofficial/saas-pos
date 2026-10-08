<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\ActionFailed;
use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\AutomationContext;
use App\Core\Automation\Actions\TransientFailure;
use App\Core\Automation\Chain\AutomationChain;
use App\Core\Automation\Chain\Cause;
use App\Core\Automation\Jobs\RunAutomationRule;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Triggers\TriggerHit;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Runs automation rules (AUTO-03, AUTO-05, AUTO-06).
 *
 * dispatch(): a trigger fired. The run is logged at once: throttled when
 * the tenant (600 a minute) or the rule (60 a minute) is over its limit,
 * else queued and handed to a RunAutomationRule job after commit. Date and
 * schedule occurrences carry a dedupe key, so one occurrence runs once.
 *
 * execute(): the job claims the run (attempts + 1), reads the document
 * fresh through its type, evaluates the conditions (false: skipped),
 * refuses a loop (the rule is already in the chain that triggered it, or
 * the chain is deeper than `automation.max_depth`: loop_blocked), then
 * runs the actions in order in one transaction, as the rule's last
 * editor, with the chain set so the changes they make carry it. A
 * permanent refusal (ActionFailed) fails the run at once; anything else
 * is retried with backoff up to `automation.attempts`. A run that fails
 * for good alerts the tenant's automation administrators.
 *
 * The rule acts as its last editor. When that person is no longer active,
 * or no longer holds what the rule needs (automation edit, seeing the
 * type, each action's permissions) at the rule's scope, the rule is
 * switched off (audited), the run fails with `run_as_unavailable` and the
 * administrators are alerted once. Fields their field rules hide never
 * leave through the actions (RBAC-05).
 */
class RuleRunner
{
    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly ConditionEvaluator $conditions,
        private readonly AutomationActions $actions,
        private readonly AutomationChain $chain,
        private readonly RuleTimezone $timezones,
        private readonly FailureAlert $alert,
        private readonly TenantContext $tenants,
        private readonly Rules $rules,
        private readonly WorkflowAccess $access,
        private readonly FieldVisibility $visibility,
        private readonly CompanyReach $reach,
    ) {}

    /** Log a run for a fired trigger and queue it; null when the occurrence already ran. */
    public function dispatch(AutomationRule $rule, TriggerHit $hit, ?Cause $cause): ?AutomationRun
    {
        $cause ??= Cause::root();

        if ($hit->dedupeKey !== null && AutomationRun::query()->where('rule_id', $rule->id)->where('dedupe_key', $hit->dedupeKey)->exists()) {
            return null;
        }

        $throttled = $this->throttled($rule, $hit);

        try {
            $run = DB::transaction(fn () => AutomationRun::create([
                'rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'trigger_type' => $hit->type,
                'trigger' => $hit->details,
                'document_type' => $hit->documentId === null ? null : $rule->document_type,
                'document_id' => $hit->documentId,
                'company_id' => $hit->companyId,
                'outcome' => $throttled ? AutomationRun::THROTTLED : AutomationRun::QUEUED,
                'chain_id' => $cause->chainId,
                'depth' => $cause->depth + 1,
                'chain' => $cause->rules,
                // A throttled occurrence keeps no key: the next scan may still run it.
                'dedupe_key' => $throttled ? null : $hit->dedupeKey,
                'error' => $throttled ? __('automation.errors.throttled') : null,
                'error_code' => $throttled ? 'throttled' : null,
                'finished_at' => $throttled ? CarbonImmutable::now() : null,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $throttled) {
            RunAutomationRule::dispatch($this->tenants->require(), $run->id)->afterCommit();
        }

        return $run;
    }

    public function execute(string $runId): void
    {
        $claimed = AutomationRun::query()->whereKey($runId)
            ->whereIn('outcome', [AutomationRun::QUEUED, AutomationRun::RETRYING])
            ->update([
                'outcome' => AutomationRun::RUNNING,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => DB::raw('coalesce(started_at, now())'),
                'next_attempt_at' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $run = AutomationRun::query()->findOrFail($runId);
        $rule = AutomationRule::query()->find($run->rule_id);

        if ($rule === null || ! $rule->enabled || $rule->isArchived()) {
            $this->finish($run, AutomationRun::SKIPPED, error: __('automation.errors.rule_off'), code: 'rule_off');

            return;
        }

        $run->rule_version = $rule->version;
        $type = $this->types->find($rule->document_type);

        if ($type === null) {
            $this->fail($run, $rule, [], __('automation.errors.type_unavailable'), 'type_unavailable');

            return;
        }

        $values = [];
        $scope = new DocumentScope($rule->company_id);

        if ($run->document_id !== null) {
            $scope = $type->scope($run->document_id);

            if ($scope === null) {
                $this->fail($run, $rule, [], __('automation.errors.document_unavailable'), 'document_unavailable');

                return;
            }

            // The document moved to another company since it was triggered.
            if ($rule->company_id !== null && $scope->companyId !== $rule->company_id) {
                $this->finish($run, AutomationRun::SKIPPED, error: __('automation.errors.company_changed'), code: 'company_changed');

                return;
            }

            $values = $type->fieldValues($run->document_id);
        }

        $timezone = $this->timezones->forCompany($scope->companyId ?? $rule->company_id) ?? 'UTC';
        $actor = $rule->actorId() === null ? null : User::query()->whereKey($rule->actorId())->where('status', User::STATUS_ACTIVE)->first();

        if ($actor === null || ! $this->actorKeepsRule($rule, $type, $actor)) {
            $this->runAsUnavailable($run, $rule);

            return;
        }

        // M5: this document is outside what the rule's user may see or change.
        if (! $this->actorReaches($rule, $type, $actor, $scope, $run->document_id !== null)) {
            $this->finish($run, AutomationRun::SKIPPED, error: __('automation.errors.out_of_scope'), code: 'out_of_scope');

            return;
        }

        if ($run->document_id !== null) {
            $result = $this->conditions->evaluate($rule->conditions, $values, $type->fieldsByName(), $timezone);
            $run->conditions = $result->outline();

            if (! $result->passed) {
                $this->finish($run, AutomationRun::SKIPPED);

                return;
            }
        }

        // AUTO-06: a rule never runs again inside the chain it started, nor past the depth limit.
        $incoming = new Cause($run->chain_id, $run->depth - 1, $run->chain);

        if ($incoming->contains($rule->id) || $run->depth > (int) config('automation.max_depth', 3)) {
            $this->finish($run, AutomationRun::LOOP_BLOCKED, error: __('automation.errors.loop_blocked', ['max' => (int) config('automation.max_depth', 3)]), code: 'loop_blocked');

            return;
        }

        $locale = Tenant::query()->whereKey($this->tenants->require())->value('default_locale') ?: 'en';
        $context = new AutomationContext($rule, $run, $type, $run->document_id, $scope, $values, $actor, $timezone, $locale, $this->visibility->hidden($actor, $type));
        $results = [];
        $current = 0;
        $committed = false;

        try {
            $this->chain->within($incoming->through($rule->id, $run->depth), function () use ($rule, $run, $context, &$results, &$current, &$committed) {
                DB::transaction(function () use ($rule, $run, $context, &$results, &$current, &$committed) {
                    // Registered first, so it runs first once the transaction has
                    // committed: before any after-commit listener that might throw.
                    DB::afterCommit(function () use (&$committed) {
                        $committed = true;
                    });

                    foreach ($rule->actions as $i => $action) {
                        $current = $i;
                        $context->actionIndex = $i;
                        $handler = $this->actions->find((string) ($action['type'] ?? ''))
                            ?? throw new ActionFailed(__('automation.errors.action_unavailable'));
                        $results[$i] = ['type' => $handler->key(), 'status' => 'done', 'result' => $handler->run($action, $context)];
                    }

                    // The outcome commits with the actions: it can never be retried after.
                    $this->finish($run, AutomationRun::SUCCEEDED, array_values($results));
                });
            });
        } catch (Throwable $e) {
            if ($committed) {
                // H2: the actions are committed; an error after that (a listener
                // reacting to their changes) is logged, never retried.
                report($e);
                AutomationRun::query()->whereKey($run->id)->update([
                    'error' => __('automation.errors.after_commit'),
                    'error_code' => 'after_commit_error',
                    'updated_at' => CarbonImmutable::now(),
                ]);

                return;
            }

            $this->failed($run, $rule, $results, $current, $e);
        }
    }

    /** The actions' transaction rolled back: fail at once, or retry. */
    private function failed(AutomationRun $run, AutomationRule $rule, array $results, int $current, Throwable $e): void
    {
        $known = $e instanceof ActionFailed || $e instanceof TransientFailure;

        if (! $known) {
            report($e);
        }

        $message = $known ? $e->getMessage() : __('automation.errors.unexpected');
        $results = $this->failedResults($rule, $results, $current, $message);

        // A refusal retrying cannot fix fails at once; anything else is tried again.
        $e instanceof ActionFailed
            ? $this->fail($run, $rule, $results, $message, 'action_failed')
            : $this->retry($run, $rule, $results, $message);
    }

    /**
     * Each action's result after a failure at $current: earlier ones were
     * undone with the transaction (a webhook in the outbox was never sent),
     * later ones never ran.
     *
     * @return list<array<string, mixed>>
     */
    private function failedResults(AutomationRule $rule, array $results, int $current, string $message): array
    {
        $out = [];

        foreach ($rule->actions as $i => $action) {
            $type = (string) ($action['type'] ?? '');

            $out[] = match (true) {
                $i < $current => [...$results[$i], 'status' => 'rolled_back'],
                $i === $current => ['type' => $type, 'status' => 'failed', 'error' => $message],
                default => ['type' => $type, 'status' => 'not_run'],
            };
        }

        return $out;
    }

    private function retry(AutomationRun $run, AutomationRule $rule, array $results, string $message): void
    {
        $attempts = (int) config('automation.attempts', 3);

        if ($run->attempts >= $attempts) {
            $this->fail($run, $rule, $results, $message, 'attempts_exhausted');

            return;
        }

        $backoff = (array) config('automation.backoff', [30, 120]);
        $delay = (int) ($backoff[$run->attempts - 1] ?? end($backoff) ?: 60);

        $run->fill([
            'outcome' => AutomationRun::RETRYING,
            'actions' => $results,
            'error' => $message,
            'error_code' => 'retrying',
            'next_attempt_at' => CarbonImmutable::now()->addSeconds($delay),
        ])->save();

        RunAutomationRule::dispatch($this->tenants->require(), $run->id)->delay($delay)->afterCommit();
    }

    private function fail(AutomationRun $run, AutomationRule $rule, array $results, string $message, string $code): void
    {
        $this->finish($run, AutomationRun::FAILED, $results, $message, $code);
        $this->alert->send($rule, $run);
    }

    /**
     * The rule's user still holds what the rule needs at all: automation
     * rights at the rule's level, and seeing the type and each action's
     * permissions somewhere. Losing one switches the rule off.
     */
    private function actorKeepsRule(AutomationRule $rule, DocumentType $type, User $actor): bool
    {
        $at = $rule->company_id === null ? Scope::tenant() : Scope::company($rule->company_id);

        if (! $actor->can('core.automation.edit', $at) || ! $this->reach->anywhere($actor, [$type->viewPermission(), $type->actPermission()])) {
            return false;
        }

        foreach ($this->permissions($rule, $type) as $permission) {
            if (! $this->reach->anywhere($actor, [$permission])) {
                return false;
            }
        }

        return true;
    }

    /** The rule's user may see this document and do the rule's actions at its own place (branch, location). */
    private function actorReaches(AutomationRule $rule, DocumentType $type, User $actor, DocumentScope $scope, bool $hasDocument): bool
    {
        if ($hasDocument && ! $this->access->seesDocument($actor, $type, $scope)) {
            return false;
        }

        foreach ($this->permissions($rule, $type) as $permission) {
            if (! $actor->can($permission, $scope->scope())) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function permissions(AutomationRule $rule, DocumentType $type): array
    {
        $permissions = [];

        foreach ($rule->actions as $action) {
            $handler = $this->actions->find((string) ($action['type'] ?? ''));
            array_push($permissions, ...($handler?->requiredPermissions($action, $type) ?? []));
        }

        return array_values(array_unique($permissions));
    }

    /**
     * The rule's user is gone or lost a permission it needs: switch the rule
     * off, fail the run, and alert the administrators once (only the run
     * that switched it off alerts; later runs find it off).
     */
    private function runAsUnavailable(AutomationRun $run, AutomationRule $rule): void
    {
        $switchedOff = $this->rules->switchOff($rule, 'run_as_unavailable');
        $this->finish($run, AutomationRun::FAILED, [], __('automation.errors.run_as_unavailable'), 'run_as_unavailable');

        if ($switchedOff) {
            $this->alert->send($rule, $run);
        }
    }

    private function finish(AutomationRun $run, string $outcome, ?array $results = null, ?string $error = null, ?string $code = null): void
    {
        $run->fill([
            'outcome' => $outcome,
            'actions' => $results ?? $run->actions,
            'error' => $error,
            'error_code' => $code,
            'finished_at' => CarbonImmutable::now(),
            'next_attempt_at' => null,
        ])->save();
    }

    /**
     * AUTO-06: over the tenant's or the rule's runs per minute, or the rule
     * ran for this document too often lately (a cool-down against edits
     * bouncing between people and rules). Date and schedule scans count
     * against their own per-tenant budget, so they never starve live
     * triggers.
     */
    private function throttled(AutomationRule $rule, TriggerHit $hit): bool
    {
        $keys = [
            ($hit->dedupeKey !== null ? 'automation:scan:' : 'automation:tenant:').$rule->tenant_id => (int) config('automation.tenant_runs_per_minute', 600),
            'automation:rule:'.$rule->id => (int) config('automation.rule_runs_per_minute', 60),
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        $documentKey = $hit->documentId === null ? null : 'automation:rule:'.$rule->id.':document:'.$hit->documentId;

        if ($documentKey !== null && RateLimiter::tooManyAttempts($documentKey, (int) config('automation.document_runs', 5))) {
            return true;
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::hit($key, 60);
        }

        if ($documentKey !== null) {
            RateLimiter::hit($documentKey, (int) config('automation.document_window', 600));
        }

        return false;
    }
}
