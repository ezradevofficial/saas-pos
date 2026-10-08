<?php

namespace App\Core\MasterData\History;

use App\Core\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * MD-07: the record types whose change history can be read, by URL name
 * (`GET history/{type}/{record}`): the model class and who may view one
 * record (default: the model's policy `view`). Modules register theirs
 * (items, payment methods ...).
 */
class HistoryTypes
{
    /** @var array<string, array{model: class-string<Model>, viewer: Closure(User, Model): bool}> */
    private array $types = [];

    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(User, Model): bool)|null  $viewer
     */
    public function register(string $type, string $model, ?Closure $viewer = null): void
    {
        $this->types[$type] = [
            'model' => $model,
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
