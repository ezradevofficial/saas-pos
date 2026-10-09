<?php

namespace App\Core\CustomForms\Entities;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormRecord;
use App\Core\CustomForms\CustomFormType;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-04 (ADR 011, ADR 012): the header fields of one custom form type,
 * `custom_form:<key>`, registered at run time. Its records are the type's
 * custom_form_records; who sees them is CustomFormAccess (RBAC-04). Lookup
 * fields may point at them (by number).
 */
class CustomFormEntity extends CustomFieldEntity
{
    public function __construct(protected readonly CustomFormType $type) {}

    public function key(): string
    {
        return $this->type->entity();
    }

    /** The type's name, typed once by the tenant (__() returns it as it is). */
    public function label(): string
    {
        return $this->type->name;
    }

    public function model(): string
    {
        return CustomFormRecord::class;
    }

    public function newQuery(): Builder
    {
        return CustomFormRecord::query()->where('type_id', $this->type->id);
    }

    public function fieldRules(): ?string
    {
        return null;
    }

    public function viewAny(User $actor): bool
    {
        return app(CustomFormAccess::class)->anywhere($actor, $this->type);
    }

    public function view(User $actor, Model $record): bool
    {
        return $record instanceof CustomFormRecord && $record->type_id === $this->type->id && app(CustomFormAccess::class)->view($actor, $record);
    }

    public function writeAny(User $actor): bool
    {
        return app(CustomFormAccess::class)->anywhere($actor, $this->type, [CustomFormAccess::CREATE, CustomFormAccess::EDIT]);
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return app(CustomFormAccess::class)->visible($query, $actor, $this->type);
    }

    /** @param CustomFormRecord $record */
    public function display(Model $record): string
    {
        return (string) ($record->number ?? $record->getKey());
    }

    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $query->where('number', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $query->orderByDesc('created_at')->orderBy('id');
    }
}
