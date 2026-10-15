<?php

namespace Modules\HR\Records;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\HR\Models\Checklist;
use Modules\HR\Models\ChecklistItem;
use Modules\HR\Models\ChecklistTemplate;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Errors;

/**
 * HR-28: onboarding and offboarding checklists from templates. Each
 * template item may be due some days after the start (the hire date for
 * onboarding, the termination date for offboarding). A checklist completes
 * when every item is done, and opens again when one is reopened.
 */
class Checklists
{
    /** @param array<string, mixed> $data validated */
    public function createTemplate(array $data): ChecklistTemplate
    {
        return Errors::transaction(function () use ($data) {
            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($data['company_id'], $data['kind']);
            }

            return ChecklistTemplate::create([
                'company_id' => $data['company_id'], 'kind' => $data['kind'], 'name' => $data['name'],
                'is_default' => (bool) ($data['is_default'] ?? false), 'items' => self::items($data['items']),
            ]);
        });
    }

    /** @param array<string, mixed> $data validated */
    public function updateTemplate(ChecklistTemplate $template, array $data): ChecklistTemplate
    {
        return Errors::transaction(function () use ($template, $data) {
            if (($data['is_default'] ?? false) === true && ! $template->is_default) {
                $this->clearDefault($template->company_id, $template->kind);
            }

            $fields = array_intersect_key($data, array_flip(['name', 'is_default']));

            if (array_key_exists('items', $data)) {
                $fields['items'] = self::items($data['items']);
            }

            $template->fill($fields)->save();

            return $template->refresh();
        });
    }

    public function start(Employee $employee, ChecklistTemplate $template, User $by): Checklist
    {
        if ($template->company_id !== $employee->company_id || $template->isArchived()) {
            throw Errors::make(422, 'template_other_company', field: 'template_id');
        }

        return Errors::transaction(function () use ($employee, $template, $by) {
            $from = $template->kind === 'offboarding' && $employee->termination_date !== null ? $employee->termination_date : $employee->hire_date;
            $checklist = Checklist::create([
                'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'kind' => $template->kind,
                'template_id' => $template->id, 'name' => $template->name, 'status' => 'open', 'created_by' => $by->id,
            ]);

            foreach (array_values($template->items) as $index => $item) {
                ChecklistItem::create([
                    'checklist_id' => $checklist->id, 'position' => $index + 1, 'title' => $item['title'],
                    'due_on' => isset($item['due_days']) ? $from->addDays((int) $item['due_days'])->toDateString() : null,
                    'role_key' => $item['role_key'] ?? null,
                ]);
            }

            return $checklist->refresh();
        });
    }

    /** HR-28: the company's default template of $kind, if any, started on the employee. */
    public function startDefault(Employee $employee, string $kind, User $by): ?Checklist
    {
        $template = ChecklistTemplate::query()->where('company_id', $employee->company_id)->where('kind', $kind)
            ->where('is_default', true)->whereNull('archived_at')->first();

        return $template === null ? null : $this->start($employee, $template, $by);
    }

    public function complete(ChecklistItem $item, User $by, ?string $note): ChecklistItem
    {
        return $this->mark($item, ['done_by' => $by->id, 'done_at' => CarbonImmutable::now(), 'note' => $note]);
    }

    public function reopen(ChecklistItem $item): ChecklistItem
    {
        return $this->mark($item, ['done_by' => null, 'done_at' => null]);
    }

    /** @param array<string, mixed> $fields */
    private function mark(ChecklistItem $item, array $fields): ChecklistItem
    {
        return Errors::transaction(function () use ($item, $fields) {
            $checklist = Checklist::query()->whereKey($item->checklist_id)->lockForUpdate()->firstOrFail();
            $item = ChecklistItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $item->fill($fields)->save();

            $open = ChecklistItem::query()->where('checklist_id', $checklist->id)->whereNull('done_at')->exists();
            $checklist->fill($open ? ['status' => 'open', 'completed_at' => null] : ['status' => 'completed', 'completed_at' => $checklist->completed_at ?? CarbonImmutable::now()])->save();

            return $item->refresh();
        });
    }

    private function clearDefault(string $companyId, string $kind): void
    {
        foreach (ChecklistTemplate::query()->where('company_id', $companyId)->where('kind', $kind)->where('is_default', true)->get() as $template) {
            $template->fill(['is_default' => false])->save();
        }
    }

    /**
     * @param  list<array{title: string, due_days?: ?int, role_key?: ?string}>  $items
     * @return list<array{title: string, due_days: ?int, role_key: ?string}>
     */
    private static function items(array $items): array
    {
        return array_map(fn (array $item) => ['title' => $item['title'], 'due_days' => isset($item['due_days']) ? (int) $item['due_days'] : null, 'role_key' => $item['role_key'] ?? null], array_values($items));
    }
}
