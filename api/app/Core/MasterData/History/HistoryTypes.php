<?php

namespace App\Core\MasterData\History;

use App\Core\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * MD-07: the record types whose change history can be read, by URL name
 * (`GET history/{type}/{record}`): the model class, the field-rules
 * resource whose hidden fields are left out of the history (RBAC-05; null:
 * no field rules apply, stated explicitly at registration) and who may view
 * one record (default: the model's policy `view`). Modules register theirs
 * (items, payment methods ...).
 */
class HistoryTypes
{
    /** @var array<string, array{model: class-string<Model>, resource: ?string, viewer: Closure(User, Model): bool}> */
    private array $types = [];

    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $resource  the FieldRules resource (RBAC-05), or null for none
     * @param  (Closure(User, Model): bool)|null  $viewer
     */
    public function register(string $type, string $model, ?string $resource, ?Closure $viewer = null): void
    {
        $this->types[$type] = [
            'model' => $model,
            'resource' => $resource,
            'viewer' => $viewer ?? fn (User $user, Model $record) => $user->can('view', $record),
        ];
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
