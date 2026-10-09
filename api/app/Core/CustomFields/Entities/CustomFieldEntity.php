<?php

namespace App\Core\CustomFields\Entities;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * CF-01, CF-03: a kind of record that carries custom fields in a
 * `custom jsonb` column (items, parties; custom forms register theirs at
 * run time, Task 7). Registered with CustomFieldEntities:
 *
 *   app(CustomFieldEntities::class)->register(new ItemEntity);
 *
 * The entity tells the custom field core who may see its records (lookups,
 * files, the form schema), which field rules resource applies (RBAC-05:
 * a field rule named `custom.<key>` hides or locks that field, `custom`
 * every field), and which sync entity carries its values to the till.
 * Every entity is also a lookup target.
 */
abstract class CustomFieldEntity implements LookupTarget
{
    /** @return class-string<Model> with a `custom` jsonb column cast to array */
    abstract public function model(): string;

    /** The field rules resource of the entity's API resource (RBAC-05), or null. */
    abstract public function fieldRules(): ?string;

    /** Whether the actor may list the entity's records at all. */
    abstract public function viewAny(User $actor): bool;

    /** Whether the actor may see this record. */
    abstract public function view(User $actor, Model $record): bool;

    /** Whether the actor may create or change records anywhere (to upload a file for a file field). */
    abstract public function writeAny(User $actor): bool;

    /** Constrain $query to the records the actor may see. */
    abstract public function visible(Builder $query, User $actor): Builder;

    /** What a person reads for a record (lookups, labels). */
    abstract public function display(Model $record): string;

    /**
     * The records whose sync payload carries `show_on_pos` values, re-stamped
     * when such a field changes (NFR-04), or null when the entity is not
     * synced to the till.
     */
    public function syncedRecords(): ?Builder
    {
        return null;
    }

    /** The table holding the `custom` column. */
    public function table(): string
    {
        $model = $this->model();

        return (new $model)->getTable();
    }

    /** @param list<string> $ids */
    public function labels(User $actor, array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && Str::isUuid($id)));

        if ($ids === [] || ! $this->viewAny($actor)) {
            return [];
        }

        $model = $this->model();

        return $this->visible($model::query(), $actor)->whereKey($ids)->get()
            ->mapWithKeys(fn (Model $record) => [(string) $record->getKey() => $this->display($record)])->all();
    }

    public function search(User $actor, string $search, int $limit): array
    {
        if (! $this->viewAny($actor)) {
            return [];
        }

        $model = $this->model();
        $query = $this->visible($model::query(), $actor)->whereNull('archived_at');
        $this->matching($query, trim($search));

        return $query->limit($limit)->get()
            ->map(fn (Model $record) => ['id' => (string) $record->getKey(), 'label' => $this->display($record)])->values()->all();
    }

    /** Narrow $query to records matching $search and order them (by name by default). */
    protected function matching(Builder $query, string $search): void
    {
        if ($search !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $query->orderBy('name')->orderBy('id');
    }
}
