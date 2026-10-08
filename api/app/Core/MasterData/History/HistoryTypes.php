<?php

namespace App\Core\MasterData\History;

use App\Core\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * MD-07: the record types whose change history can be read, by URL name
 * (`GET history/{type}/{record}`): the model class, the field-rules
 * resource whose hidden fields are left out of the history (RBAC-05; null:
 * no field rules apply, stated explicitly at registration) and who may view
 * one record (default: the model's policy `view`), and audit keys derived
 * from a field (hidden with it, e.g. `secrets_changed` with `secrets`).
 * Modules register theirs (items, payment methods ...).
 */
class HistoryTypes
{
    /** @var array<string, array{model: class-string<Model>, resource: ?string, viewer: Closure(User, Model): bool, derived: array<string, list<string>>}> */
    private array $types = [];

    /** @var array<string, list<array{model: class-string<Model>, ids: Closure(User, Model): ?Builder}>> */
    private array $related = [];

    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $resource  the FieldRules resource (RBAC-05), or null for none
     * @param  (Closure(User, Model): bool)|null  $viewer
     * @param  array<string, list<string>>  $derived  field => audit keys hidden whenever the field is
     */
    public function register(string $type, string $model, ?string $resource, ?Closure $viewer = null, array $derived = []): void
    {
        $this->types[$type] = [
            'model' => $model,
            'resource' => $resource,
            'viewer' => $viewer ?? fn (User $user, Model $record) => $user->can('view', $record),
            'derived' => $derived,
        ];
    }

    /**
     * $hidden fields plus the audit keys derived from them (RBAC-05).
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public function withDerived(string $type, array $hidden): array
    {
        $derived = $this->types[$type]['derived'] ?? [];

        return array_values(array_unique([...$hidden, ...array_merge([], ...array_map(fn (string $field) => $derived[$field] ?? [], $hidden))]));
    }

    /**
     * Changes of other records shown in a record's history: an item's and
     * a price list's prices (`core.item_price.*`). `$ids(user, record)`
     * returns a query selecting the related records' ids the user may see
     * there, or null when they may see none (permissions, RBAC-05).
     *
     * @param  class-string<Model>  $model
     * @param  Closure(User, Model): ?Builder  $ids
     */
    public function relate(string $type, string $model, Closure $ids): void
    {
        $this->related[$type][] = ['model' => $model, 'ids' => $ids];
    }

    /**
     * The related records' morph class => a query of their ids the user may see.
     *
     * @return array<string, Builder>
     */
    public function related(string $type, User $user, Model $record): array
    {
        $related = [];

        foreach ($this->related[$type] ?? [] as $entry) {
            $ids = ($entry['ids'])($user, $record);

            if ($ids !== null) {
                $related[(new $entry['model'])->getMorphClass()] = $ids;
            }
        }

        return $related;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return class-string<Model> */
    public function model(string $type): string
    {
        return $this->types[$type]['model'];
    }

    public function resource(string $type): ?string
    {
        return $this->types[$type]['resource'];
    }

    public function canView(string $type, User $user, Model $record): bool
    {
        return ($this->types[$type]['viewer'])($user, $record);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->types);
    }
}
