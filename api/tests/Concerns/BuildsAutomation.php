<?php

namespace Tests\Concerns;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Runtime\Rules;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestDocuments;

/**
 * AUTO-01..AUTO-07 tests: the workflow test types plus TestTaskType (every
 * automation capability), an organisation (BuildsWorkflows), and helpers
 * to save rules and change tasks as a module would. Call
 * setUpAutomation() in setUp().
 */
trait BuildsAutomation
{
    use BuildsWorkflows;

    protected function setUpAutomation(): void
    {
        TestTaskType::$failNext = 0;
        $this->setUpWorkflows();
        app(DocumentTypeRegistry::class)->register(TestTaskType::class);
        RateLimiter::clear('automation:tenant:'.$this->owner->tenant_id);
    }

    protected function tasks(): TestTaskType
    {
        return app(DocumentTypeRegistry::class)->get(TestTaskType::KEY);
    }

    /** A task at branch A, created as a module would (RecordChanged fires). */
    protected function createTask(array $values = [], ?DocumentScope $scope = null): string
    {
        return $this->inTenant(fn () => $this->tasks()->create(
            $values + ['title' => 'Count stock', 'status' => 'open'],
            $scope ?? new DocumentScope($this->acme->id, $this->branchA->id),
            $this->owner,
        ));
    }

    /** A task stored without raising anything (as if it existed before the rule). */
    protected function quietTask(array $values = [], ?DocumentScope $scope = null): string
    {
        return $this->inTenant(fn () => TestDocuments::create(
            TestTaskType::KEY,
            $values + ['title' => 'Count stock', 'status' => 'open'],
            $scope ?? new DocumentScope($this->acme->id, $this->branchA->id),
        ));
    }

    protected function changeTask(string $id, array $values): void
    {
        $this->inTenant(fn () => $this->tasks()->write($id, $values, $this->owner));
    }

    /** @return array<string, mixed> */
    protected function taskValues(string $id): array
    {
        return $this->inTenant(fn () => $this->tasks()->fieldValues($id));
    }

    /** Save a rule on tasks (enabled unless said otherwise) as $by (the owner). */
    protected function saveRule(array $trigger, array $actions, array $extra = [], ?User $by = null): AutomationRule
    {
        return $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Rule '.count(AutomationRule::query()->get()),
            'document_type' => TestTaskType::KEY,
            'trigger' => $trigger,
            'actions' => $actions,
            'enabled' => true,
            ...$extra,
        ], $by ?? $this->owner));
    }

    protected function notifyOwner(string $subject = 'Task {title}', string $message = 'Status is {status}.'): array
    {
        return ['type' => 'notify', 'to' => ["user:{$this->owner->id}"], 'subject' => $subject, 'message' => $message];
    }

    /** @return Collection<int, AutomationRun> runs of $rule (all rules when null), oldest first */
    protected function runs(?AutomationRule $rule = null): Collection
    {
        return $this->inTenant(fn () => AutomationRun::query()
            ->when($rule !== null, fn ($q) => $q->where('rule_id', $rule->id))
            ->orderBy('created_at')->orderBy('id')->get());
    }

    protected function kes(int|string $minor): array
    {
        return ['amount_minor' => (string) $minor, 'currency' => 'KES'];
    }
}
