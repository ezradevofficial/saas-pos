<?php

namespace App\Core\Layouts;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\Identity\Models\User;
use App\Core\Lists\ListColumn;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\FieldRules;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/**
 * LAY-04, RBAC-05: the lists that may have saved views, by the web list's
 * id (a list view document's key), and which server list each stands for,
 * so a saved view never shows a column, a sort or a filter the reader's
 * field rules hide. Every list the web offers views on registers its id;
 * lists whose resources apply field rules register their definition too.
 * A key with no definition hides nothing (its resource applies no rules).
 * Families of lists (a custom form's records) register a pattern.
 *
 * Only the definition's static description is read (its columns, sorts,
 * filters and field rules resource), so it is built without its constructor.
 */
class ListViewCatalogue
{
    /** @var array<string, class-string<ListDefinition>|null> */
    private array $lists = [];

    /** @var list<string> */
    private array $patterns = [];

    /** @param class-string<ListDefinition>|null $definition */
    public function register(string $key, ?string $definition = null): void
    {
        $this->lists[$key] = $definition;
    }

    /** A family of list ids, e.g. `/^custom-forms-[a-z][a-z0-9_]{0,29}\z/`. */
    public function registerPattern(string $pattern): void
    {
        $this->patterns[] = $pattern;
    }

    /** Whether $key names a list that may have saved views (LAY-04). */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->lists)) {
            return true;
        }

        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    public function definition(string $key): ?ListDefinition
    {
        $class = $this->lists[$key] ?? null;

        return $class === null ? null : (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /**
     * What the reader may not see in the list: column keys (as the export
     * names them) and sort keys built from a field their rules hide, and
     * the hidden field names themselves (custom fields as `custom.<key>`,
     * CustomFieldAccess).
     *
     * @return array{columns: list<string>, sorts: list<string>, fields: list<string>}
     */
    public function hiddenFor(string $key, User $reader): array
    {
        $definition = $this->definition($key);
        $resource = $definition?->fieldRules();

        if ($definition === null || $resource === null) {
            return ['columns' => [], 'sorts' => [], 'fields' => []];
        }

        $entity = $definition->customFieldEntity();
        $hidden = [
            ...app(FieldRules::class)->for($reader, $resource)['hidden'],
            ...($entity === null ? [] : app(CustomFieldAccess::class)->hiddenNames($reader, $entity)),
        ];

        if ($hidden === []) {
            return ['columns' => [], 'sorts' => [], 'fields' => []];
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

        return ['columns' => $columns, 'sorts' => $sorts, 'fields' => array_values(array_unique($hidden))];
    }

    /**
     * Filter values that name a record no longer there (archived or gone),
     * per filter name: one query per referenced table.
     *
     * @param  array<string, list<string>>  $values  filter name => values used by the views
     * @return array<string, list<string>> filter name => values to drop
     */
    public function goneReferences(ListDefinition $definition, array $values): array
    {
        $gone = [];

        foreach ($definition->filterReferences() as $filter => [$table, $archivable]) {
            $ids = array_values(array_unique(array_filter($values[$filter] ?? [], fn ($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1)));
            $others = array_values(array_diff($values[$filter] ?? [], $ids));

            if ($ids !== []) {
                $query = DB::connection(TenantContext::CONNECTION)->table($table)->whereIn('id', $ids);

                if ($archivable) {
                    $query->whereNull('archived_at');
                }

                $found = array_map('strtolower', $query->pluck('id')->all());
                $others = [...$others, ...array_filter($ids, fn ($id) => ! in_array(strtolower($id), $found, true))];
            }

            if ($others !== []) {
                $gone[$filter] = $others;
            }
        }

        return $gone;
    }
}
