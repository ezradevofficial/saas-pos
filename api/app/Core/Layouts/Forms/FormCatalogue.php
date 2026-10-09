<?php

namespace App\Core\Layouts\Forms;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldDefinitions;
use Closure;

/**
 * LAY-03, LAY-07: the forms whose layouts tenants design, and what each
 * holds today: its built-in fields and the active custom fields of its
 * entity (as `custom.<key>`). Core registers the item and party forms;
 * custom forms (CF-04) add theirs at run time through a source, a closure
 * answering the current tenant's forms.
 *
 * The catalogue is what CatalogueMerge lays a stored layout over, so a
 * field added later (a platform update, a new custom field) appears in
 * its group's section, or at the end of the last section.
 */
class FormCatalogue
{
    /** Custom field types shown across every column by default. */
    public const WIDE_TYPES = ['long_text', 'money', 'multi_select', 'file'];

    /** @var array<string, FormDefinition> */
    private array $forms = [];

    /** @var list<Closure(): list<FormDefinition>> */
    private array $sources = [];

    public function __construct(private readonly CustomFieldDefinitions $definitions) {}

    public function register(FormDefinition $form): void
    {
        $this->forms[$form->key] = $form;
    }

    /** @param Closure(): list<FormDefinition> $source the current tenant's forms */
    public function registerSource(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function find(string $key): ?FormDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, FormDefinition> */
    public function all(): array
    {
        $forms = $this->forms;

        foreach ($this->sources as $source) {
            foreach ($source() as $form) {
                $forms[$form->key] ??= $form;
            }
        }

        return $forms;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Every field the form holds now: built-ins, then custom fields by
     * position. Entries carry `default_label`, `required`, `has_default`,
     * `wide`, `source` (builtin, custom) and `type` (custom fields), plus
     * the `group` hint CatalogueMerge reads.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(FormDefinition $form): array
    {
        $fields = array_map(fn (array $field) => [
            'id' => $field['id'],
            'default_label' => $form->text($field['label']),
            'required' => (bool) ($field['required'] ?? false),
            'has_default' => (bool) ($field['has_default'] ?? false),
            'wide' => (bool) ($field['wide'] ?? false),
            'source' => 'builtin',
            'type' => null,
            'group' => $field['group'],
        ], $form->fields);

        if ($form->entity !== null) {
            foreach ($this->definitions->active($form->entity) as $definition) {
                /** @var CustomFieldDefinition $definition */
                $fields[] = [
                    'id' => 'custom.'.$definition->key,
                    'default_label' => $definition->label,
                    'required' => $definition->required && $definition->type !== 'formula',
                    'has_default' => $definition->default_value !== null,
                    'wide' => in_array($definition->type, self::WIDE_TYPES, true),
                    'source' => 'custom',
                    'type' => $definition->type,
                    'group' => $form->customGroup,
                ];
            }
        }

        return $fields;
    }

    /**
     * The layout when none is published: the form's sections with their
     * built-in fields; custom fields join through the merge (their group).
     *
     * @return array{tabs: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function defaults(FormDefinition $form): array
    {
        return [
            'tabs' => [],
            'sections' => array_map(fn (array $section) => [
                'id' => $section['id'],
                'title' => $form->text($section['title'] ?? null),
                'tab' => null,
                'columns' => $section['columns'] ?? 2,
                'fields' => array_values(array_map(
                    fn (array $field) => ['id' => $field['id']],
                    array_filter($form->fields, fn (array $field) => $field['group'] === $section['id']),
                )),
            ], $form->sections),
        ];
    }
}
