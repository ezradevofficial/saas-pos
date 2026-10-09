<?php

namespace App\Core\Layouts\Kinds;

use App\Core\Configuration\CatalogueMerge;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\CustomFields\CustomFieldAccess;
use App\Core\Identity\Models\User;
use App\Core\Layouts\Forms\FormCatalogue;
use App\Core\Layouts\Forms\FormDefinition;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\ScopeResolver;

/**
 * LAY-03: the layout of one form (the key: `item`, `party`,
 * `custom_form.<key>`), for the tenant or one role. Optional tabs, each
 * section in a tab when there are tabs; sections with a title (typed
 * once, not translated), 1 to 3 columns and their fields in order. A
 * field may be hidden, hidden for some roles (`hidden_roles`), relabelled,
 * given help text, or span every column (`width: full`).
 *
 * Hiding is presentation only: field rules (RBAC-05) still decide what a
 * user sees and changes, and the API refuses what they may not write. A
 * required field without a default can't be hidden (`required_hidden`),
 * or nobody could create a record through the form.
 *
 * LAY-07: the merger lays the layout over the form's catalogue
 * (FormCatalogue): a field it no longer has is skipped; a new one
 * appears in its group's section, else at the end of the last section.
 *
 * The presenter answers for the reader: `hidden_roles` becomes `hidden`
 * when every role the reader holds is listed (several roles: the most
 * permissive answer, as field rules), and custom fields their field rules
 * hide are dropped.
 */
final class FormLayout
{
    public const KEY = 'form_layout';

    public const LAYOUT_KEYS = ['hidden_roles'];

    private const ID = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    private const FIELD = '/^(custom\.)?[a-z][a-z0-9_]{0,59}$/';

    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['sections'],
        'additional' => false,
        'properties' => [
            'tabs' => ['type' => 'array', 'max' => 10, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id', 'title'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => self::ID],
                    'title' => ['type' => 'string', 'min' => 1, 'max' => 60],
                ],
            ]],
            'sections' => ['type' => 'array', 'min' => 1, 'max' => 30, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id', 'fields'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => self::ID],
                    'title' => ['type' => 'string', 'nullable' => true, 'max' => 80],
                    'tab' => ['type' => 'string', 'nullable' => true, 'pattern' => self::ID],
                    'columns' => ['type' => 'integer', 'enum' => [1, 2, 3]],
                    'fields' => ['type' => 'array', 'max' => 200, 'items' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'additional' => false,
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => self::FIELD],
                            'hidden' => ['type' => 'boolean'],
                            'hidden_roles' => ['type' => 'array', 'max' => 50, 'items' => ['type' => 'string', 'pattern' => self::UUID]],
                            'label' => ['type' => 'string', 'nullable' => true, 'max' => 100],
                            'help' => ['type' => 'string', 'nullable' => true, 'max' => 255],
                            'width' => ['type' => 'string', 'nullable' => true, 'enum' => ['full']],
                        ],
                    ]],
                ],
            ]],
        ],
    ];

    public static function kind(): ConfigKind
    {
        return new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload, ?ConfigDocument $document = null) => self::problems($payload, $document?->key ?? ConfigKind::DEFAULT_KEY),
            scopes: [ConfigDocument::TENANT, ConfigDocument::ROLE],
            permissions: LayoutsServiceProvider::PERMISSIONS,
            merger: fn (array $payload, ConfigKind $kind, string $key) => self::merge($payload, $key, $kind->layoutKeys()),
            defaults: fn (string $key) => ($form = self::catalogue()->find($key)) === null ? null : self::catalogue()->defaults($form),
            keys: fn () => self::catalogue()->keys(),
            presenter: fn (array $payload, string $key, User $reader) => self::present($payload, $key, $reader),
            layoutKeys: self::LAYOUT_KEYS,
        );
    }

    private static function catalogue(): FormCatalogue
    {
        return app(FormCatalogue::class);
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public static function problems(array $payload, string $key): array
    {
        $problems = PayloadSchema::check($payload, self::SCHEMA);

        if ($problems !== []) {
            return $problems;
        }

        $form = self::catalogue()->find($key);
        $fields = $form === null ? [] : array_column(self::catalogue()->fields($form), null, 'id');
        $tabs = array_column($payload['tabs'] ?? [], 'id');
        $seen = [];
        $roleIds = [];

        foreach ($payload['sections'] as $s => $section) {
            $tab = $section['tab'] ?? null;

            if ($tab !== null && ! in_array($tab, $tabs, true)) {
                $problems[] = PayloadSchema::problem("sections.{$s}.tab", 'unknown_tab', ['value' => $tab]);
            } elseif ($tab === null && $tabs !== []) {
                $problems[] = PayloadSchema::problem("sections.{$s}.tab", 'tab_required');
            }

            foreach ($section['fields'] as $f => $entry) {
                $path = "sections.{$s}.fields.{$f}";
                $id = $entry['id'];

                if (! isset($fields[$id])) {
                    $problems[] = PayloadSchema::problem("{$path}.id", 'unknown_field', ['value' => $id]);

                    continue;
                }

                if (isset($seen[$id])) {
                    $problems[] = PayloadSchema::problem("{$path}.id", 'duplicate', ['value' => $id]);
                }

                $seen[$id] = true;
                $hides = ($entry['hidden'] ?? false) === true || ($entry['hidden_roles'] ?? []) !== [];

                if ($hides && $fields[$id]['required'] && ! $fields[$id]['has_default']) {
                    $problems[] = PayloadSchema::problem($path, 'required_hidden', ['value' => $fields[$id]['default_label']]);
                }

                foreach ($entry['hidden_roles'] ?? [] as $r => $roleId) {
                    $roleIds["{$path}.hidden_roles.{$r}"] = strtolower($roleId);
                }
            }
        }

        // Under row-level security: another tenant's role does not exist.
        $known = $roleIds === [] ? [] : Role::query()->whereKey(array_values(array_unique($roleIds)))->whereNull('archived_at')->pluck('id')->map(fn ($id) => strtolower((string) $id))->all();

        foreach ($roleIds as $path => $roleId) {
            if (! in_array($roleId, $known, true)) {
                $problems[] = PayloadSchema::problem($path, 'unknown_role', ['value' => $roleId]);
            }
        }

        return $problems;
    }

    /**
     * LAY-07: the layout over the form's current catalogue. A new field
     * whose group the layout no longer has goes to the last section.
     *
     * @param  list<string>  $layoutKeys
     */
    public static function merge(array $payload, string $key, array $layoutKeys): array
    {
        $form = self::catalogue()->find($key);

        if ($form === null) {
            return $payload;
        }

        $sections = array_values(array_filter(is_array($payload['sections'] ?? null) ? $payload['sections'] : [], 'is_array'));
        $ids = array_map(fn (array $section) => $section['id'] ?? null, $sections);
        $last = $ids === [] ? null : end($ids);
        $catalogue = array_map(function (array $field) use ($ids, $last) {
            if ($last !== null && ! in_array($field['group'], $ids, true)) {
                $field['group'] = $last;
            }

            return $field;
        }, self::catalogue()->fields($form));

        $payload['tabs'] = is_array($payload['tabs'] ?? null) ? array_values($payload['tabs']) : [];
        $payload['sections'] = CatalogueMerge::grouped(
            $sections,
            $catalogue,
            'fields',
            CatalogueMerge::APPEND,
            ['id' => 'main', 'title' => null, 'tab' => null, 'columns' => 2],
            layoutKeys: $layoutKeys,
        );

        return $payload;
    }

    /** The layout as $reader sees it: per-role hiding applied, hidden custom fields dropped (RBAC-05). */
    public static function present(array $payload, string $key, User $reader): array
    {
        $form = self::catalogue()->find($key);
        $roles = array_map('strtolower', app(ScopeResolver::class)->roleIds($reader));
        $hiddenCustom = $form?->entity === null ? [] : app(CustomFieldAccess::class)->hiddenNames($reader, $form->entity);

        $payload['sections'] = array_map(function ($section) use ($roles, $hiddenCustom) {
            if (! is_array($section)) {
                return $section;
            }

            $section['fields'] = array_values(array_map(function (array $entry) use ($roles) {
                $listed = array_map('strtolower', (array) ($entry['hidden_roles'] ?? []));

                if ($listed !== [] && $roles !== [] && array_diff($roles, $listed) === []) {
                    $entry['hidden'] = true;
                }

                unset($entry['hidden_roles']);

                return $entry;
            }, array_filter((array) ($section['fields'] ?? []), fn ($entry) => is_array($entry) && ! in_array($entry['id'] ?? null, $hiddenCustom, true))));

            return $section;
        }, (array) ($payload['sections'] ?? []));

        return $payload;
    }

    /** For the designer: the form's fields and its default layout. */
    public static function describe(FormDefinition $form): array
    {
        return [
            'key' => $form->key,
            'label' => $form->text($form->label),
            'fields' => self::catalogue()->fields($form),
            'defaults' => self::merge(self::catalogue()->defaults($form), $form->key, [...CatalogueMerge::LAYOUT_KEYS, ...self::LAYOUT_KEYS]),
        ];
    }
}
