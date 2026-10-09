<?php

namespace App\Core\Layouts\Forms;

/**
 * LAY-03: a form whose layout tenants design (the `form_layout` kind): its
 * config key (`item`, `party`, `custom_form.<key>`), the custom field
 * entity whose fields it shows, its default sections and its built-in
 * fields (the platform's own, rendered by the web form).
 *
 * A built-in field is `['id', 'label', 'required', 'has_default', 'wide',
 * 'group']`: `label` a translation key (or, with `$translated` false, text
 * typed by the tenant), `required` and `has_default` decide whether a
 * layout may hide it (a required field without a default can't be
 * hidden), `wide` spans every column, `group` is its default section. A
 * section is `['id', 'title', 'columns']`, its title translated the same
 * way (null: no title).
 */
final class FormDefinition
{
    /**
     * @param  list<array{id: string, title: ?string, columns?: int}>  $sections
     * @param  list<array{id: string, label: string, required?: bool, has_default?: bool, wide?: bool, group: string}>  $fields
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $entity,
        public readonly array $sections,
        public readonly array $fields,
        public readonly string $label,
        public readonly bool $translated = true,
        public readonly string $customGroup = 'custom',
    ) {}

    public function text(?string $value): ?string
    {
        return $value === null ? null : ($this->translated ? __($value) : $value);
    }
}
