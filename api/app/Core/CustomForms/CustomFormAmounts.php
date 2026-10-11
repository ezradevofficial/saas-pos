<?php

namespace App\Core\CustomForms;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\Identity\Models\User;

/**
 * RBAC-05 for a custom form record's money (CF-05): its `totals` repeat the
 * line fields and its `amount` repeats one line total or header value, so
 * both follow the field's visibility (CustomFieldAccess).
 *
 * - totals(): the totals without those of line fields hidden from the
 *   viewer;
 * - amount(): null when the field it comes from is hidden from the viewer;
 * - amountHidden(): whether the amount could come from a field hidden from
 *   the viewer (any money line field, any money header field). Lists drop
 *   the amount column and sort for them, and the approvals inbox, notices
 *   and emails leave the amount out (DocumentType::hiddenSummaryFields).
 */
class CustomFormAmounts
{
    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldAccess $access,
    ) {}

    /** @return array<string, mixed> */
    public function totals(?User $viewer, CustomFormRecord $record): array
    {
        $type = $record->type;
        $hidden = $type->has_lines ? $this->access->for($viewer, $type->lineEntity())['hidden'] : [];

        return array_diff_key((array) ($record->totals ?? []), array_flip($hidden));
    }

    /** @return array{amount_minor: string, currency: string}|null */
    public function amount(?User $viewer, CustomFormRecord $record): ?array
    {
        $amount = $record->amount()?->jsonSerialize();

        if ($amount === null || $viewer === null) {
            return $amount;
        }

        [$entity, $key] = $this->source($record) ?? [null, null];

        // A source no longer found (the field was archived): hidden when any candidate is.
        if ($entity === null) {
            return $this->amountHidden($viewer, $record->type) ? null : $amount;
        }

        return in_array($key, $this->access->for($viewer, $entity)['hidden'], true) ? null : $amount;
    }

    public function amountHidden(?User $viewer, CustomFormType $type): bool
    {
        if ($viewer === null) {
            return false;
        }

        foreach ($this->candidates($type) as [$entity, $key]) {
            if (in_array($key, $this->access->for($viewer, $entity)['hidden'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the stored amount comes from, as CustomForms::applyTotals
     * chose it: the first money line total, else the first money header value.
     *
     * @return array{0: string, 1: string}|null
     */
    private function source(CustomFormRecord $record): ?array
    {
        $type = $record->type;

        foreach ($this->candidates($type) as [$entity, $key]) {
            $value = $entity === $type->entity() ? ($record->custom[$key] ?? null) : ($record->totals[$key] ?? null);

            if (is_array($value)) {
                return [$entity, $key];
            }
        }

        return null;
    }

    /** @return list<array{0: string, 1: string}> the money line fields, then the money header fields */
    private function candidates(CustomFormType $type): array
    {
        $candidates = [];

        if ($type->has_lines) {
            foreach ($this->definitions->active($type->lineEntity())->where('type', 'money') as $field) {
                $candidates[] = [$type->lineEntity(), $field->key];
            }
        }

        foreach ($this->definitions->active($type->entity())->where('type', 'money') as $field) {
            $candidates[] = [$type->entity(), $field->key];
        }

        return $candidates;
    }
}
