<?php

namespace App\Core\Layouts;

use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\FieldRules;
use ReflectionClass;

/**
 * LAY-04, RBAC-05: which server list a list view key (the web list's id)
 * stands for, so a saved view never shows a column, a sort or a filter
 * the reader's field rules hide. Lists whose resources apply field rules
 * register here; a key that is not registered hides nothing (its
 * resource applies no rules).
 *
 * Only the definition's static description is read (its columns, sorts
 * and field rules resource), so it is built without its constructor.
 */
class ListViewCatalogue
{
    /** @var array<string, class-string<ListDefinition>> */
    private array $lists = [];

    /** @param class-string<ListDefinition> $definition */
    public function register(string $key, string $definition): void
    {
        $this->lists[$key] = $definition;
    }

    public function definition(string $key): ?ListDefinition
    {
        $class = $this->lists[$key] ?? null;

        return $class === null ? null : (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * What the reader may not see in the list: column keys (as the export
     * names them) and sort keys built from a field their rules hide.
     *
     * @return array{columns: list<string>, sorts: list<string>}
     */
    public function hiddenFor(string $key, User $reader): array
    {
        $definition = $this->definition($key);
        $resource = $definition?->fieldRules();

        if ($definition === null || $resource === null) {
            return ['columns' => [], 'sorts' => []];
        }

        $hidden = app(FieldRules::class)->for($reader, $resource)['hidden'];

        if ($hidden === []) {
            return ['columns' => [], 'sorts' => []];
        }

        $columns = array_values(array_map(
            fn (ListColumn $column) => $column->key,
            array_filter($definition->columns(), fn (ListColumn $column) => $definition->hides($column->fields, $hidden)),
        ));
        $sorts = [];

        foreach ($definition->sorts() as $sortKey => $sort) {
            if ($definition->hides($sort->fields, $hidden)) {
                $sorts[] = (string) $sortKey;
            }
        }

        return ['columns' => $columns, 'sorts' => $sorts];
    }
}
