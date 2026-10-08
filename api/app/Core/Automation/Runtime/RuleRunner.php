<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\ActionFailed;
use App\Core\Automation\Actions\AutomationActions;
use App\Core\Automation\Actions\AutomationContext;
use App\Core\Automation\Actions\TransientFailure;
use App\Core\Automation\Actions\WebhookAction;
use App\Core\Automation\Chain\AutomationChain;
use App\Core\Automation\Chain\Cause;
use App\Core\Automation\Jobs\RunAutomationRule;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Triggers\TriggerHit;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
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
    ) {}

    /** Log a run for a fired trigger and queue it; null when the occurrence already ran. */
    public function dispatch(AutomationRule $rule, TriggerHit $hit, ?Cause $cause): ?AutomationRun
    {
        $cause ??= Cause::root();

        if ($hit->dedupeKey !== null && AutomationRun::query()->where('rule_id', $rule->id)->where('dedupe_key', $hit->dedupeKey)->exists()) {
            return null;
        }

        $throttled = $this->throttled($rule);

        try {
            $run = DB::transaction(fn () => AutomationRun::create([
                'rule_id' => $rule->id,
                'rule_version' => $rule->version,
                'trigger_type' => $hit->type,
                'trigger' => $hit->details,
                'document_type' => $hit->documentId === null ? null : $rule->document_type,
                'document_id' => $hit->documentId,
                'outcome' => $throttled ? AutomationRun::THROTTLED : AutomationRun::QUEUED,
                'chain_id' => $cause->chainId,
                'depth' => $cause->depth + 1,
                'chain' => $cause->rules,
                'dedupe_key' => $hit->dedupeKey,
                'error' => $throttled ? __('automation.errors.throttled') : null,
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
            $this->finish($run, AutomationRun::SKIPPED, error: __('automation.errors.rule_off'));

            return;
        }

        $run->rule_version = $rule->version;
        $type = $this->types->find($rule->document_type);

        if ($type === null) {
            $this->fail($run, $rule, [], __('automation.errors.type_unavailable'));

            return;
        }

        $values = [];
        $scope = new DocumentScope($rule->company_id);

        if ($run->document_id !== null) {
            $scope = $type->scope($run->document_id);

            if ($scope === null) {
                $this->fail($run, $rule, [], __('automation.errors.document_unavailable'));

                return;
            }

            $values = $type->fieldValues($run->document_id);
        }

        $timezone = $this->timezones->forCompany($scope->companyId ?? $rule->company_id) ?? 'UTC';

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
            $this->finish($run, AutomationRun::LOOP_BLOCKED, error: __('automation.errors.loop_blocked', ['max' => (int) config('automation.max_depth', 3)]));

            return;
        }

        $actor = $rule->actorId() === null ? null : User::query()->whereKey($rule->actorId())->where('status', User::STATUS_ACTIVE)->first();
        $locale = Tenant::query()->whereKey($this->tenants->require())->value('default_locale') ?: 'en';
        $context = new AutomationContext($rule, $run, $type, $run->document_id, $scope, $values, $actor, $timezone, $locale);
        $results = [];
        $current = 0;

        try {
            $this->chain->within($incoming->through($rule->id, $run->depth), function () use ($rule, $context, &$results, &$current) {
                DB::transaction(function () use ($rule, $context, &$results, &$current) {
                    foreach ($rule->actions as $i => $action) {
                        $current = $i;
                        $handler = $this->actions->find((string) ($action['type'] ?? ''))
                            ?? throw new ActionFailed(__('automation.errors.action_unavailable'));
                        $results[$i] = ['type' => $handler->key(), 'status' => 'done', 'result' => $handler->run($action, $context)];
                    }
                });
            });
        } catch (ActionFailed $e) {
            $this->fail($run, $rule, $this->failedResults($rule, $results, $current, $e->getMessage()), $e->getMessage());

            return;
        } catch (TransientFailure $e) {
            $this->retry($run, $rule, $this->failedResults($rule, $results, $current, $e->getMessage()), $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $message = __('automation.errors.unexpected');
            $this->retry($run, $rule, $this->failedResults($rule, $results, $current, $message), $message);

            return;
        }

        $this->finish($run, AutomationRun::SUCCEEDED, array_values($results));
    }

    /**
     * Each action's result after a failure at $current: earlier ones were
     * undone with the transaction (a webhook already sent stays sent),
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
                $i < $current => [...$results[$i], 'status' => $type === WebhookAction::KEY ? 'done' : 'rolled_back'],
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
            $this->fail($run, $rule, $results, $message);

            return;
        }

        $backoff = (array) config('automation.backoff', [30, 120]);
        $delay = (int) ($backoff[$run->attempts - 1] ?? end($backoff) ?: 60);

        $run->fill([
            'outcome' => AutomationRun::RETRYING,
            'actions' => $results,
            'error' => $message,
            'next_attempt_at' => CarbonImmutable::now()->addSeconds($delay),
        ])->save();

        RunAutomationRule::dispatch($this->tenants->require(), $run->id)->delay($delay)->afterCommit();
    }

    private function fail(AutomationRun $run, AutomationRule $rule, array $results, string $message): void
    {
        $this->finish($run, AutomationRun::FAILED, $results, $message);
        $this->alert->send($rule, $run);
    }

    private function finish(AutomationRun $run, string $outcome, ?array $results = null, ?string $error = null): void
    {
        $run->fill([
            'outcome' => $outcome,
            'actions' => $results ?? $run->actions,
            'error' => $error,
            'finished_at' => CarbonImmutable::now(),
            'next_attempt_at' => null,
        ])->save();
    }

    /** AUTO-06: over the tenant's or the rule's runs per minute. */
    private function throttled(AutomationRule $rule): bool
    {
        $tenantKey = 'automation:tenant:'.$rule->tenant_id;
        $ruleKey = 'automation:rule:'.$rule->id;

        if (RateLimiter::tooManyAttempts($tenantKey, (int) config('automation.tenant_runs_per_minute', 600))
            || RateLimiter::tooManyAttempts($ruleKey, (int) config('automation.rule_runs_per_minute', 60))) {
            return true;
        }

        RateLimiter::hit($tenantKey, 60);
        RateLimiter::hit($ruleKey, 60);

        return false;
    }
}
