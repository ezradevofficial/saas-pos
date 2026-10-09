<?php

namespace App\Core\CustomFields;

use App\Core\Currency\Money;
use App\Core\Identity\Models\User;

/**
 * CF-03, RBAC-05: a record's custom values as API resources show them to
 * a user: active fields only, without the ones hidden from the user
 * (CustomFieldAccess), in the stored shapes (CustomFieldTypes) except
 *
 *   lookup  {id, label}; label null when the user can't see the target
 *   file    {id, name, mime, size, url} with a temporary URL for the user
 *
 * Lookup labels and files are read once per request and target.
 */
class CustomFieldPresenter
{
    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldAccess $access,
        private readonly CustomFieldEntities $entities,
        private readonly CustomFieldFiles $files,
    ) {}

    /**
     * @param  array<string, mixed>|null  $custom  the stored values
     * @return array<string, mixed>
     */
    public function present(?User $user, string $entity, ?array $custom): array
    {
        $custom ??= [];
        $hidden = $this->access->for($user, $entity)['hidden'];
        $shown = [];

        foreach ($this->definitions->active($entity) as $field) {
            if (in_array($field->key, $hidden, true) || ! array_key_exists($field->key, $custom) || $custom[$field->key] === null) {
                continue;
            }

            $value = $custom[$field->key];

            $shown[$field->key] = match ($field->type) {
                'lookup' => ['id' => $value, 'label' => $user === null ? null : $this->label((string) $field->lookup_target, (string) $value, $user)],
                'file' => $this->file((string) $value, $user),
                // jsonb reorders keys: give them back in a stable order.
                'money' => ['amount_minor' => (string) $value['amount_minor'], 'currency' => (string) $value['currency']],
                default => $value,
            };
        }

        return $shown;
    }

    /**
     * Display text of each visible value (exports, merge fields, notifications):
     * lookups by label, files by name, yes/no and options as typed.
     *
     * @param  array<string, mixed>|null  $custom
     * @return array<string, string>
     */
    public function text(?User $user, string $entity, ?array $custom): array
    {
        $values = $this->present($user, $entity, $custom);
        $fields = $this->definitions->byKey($entity);
        $texts = [];

        foreach ($values as $key => $value) {
            $texts[$key] = self::display($fields[$key], $value);
        }

        return $texts;
    }

    /** One presented value as text. */
    public static function display(CustomFieldDefinition $field, mixed $value): string
    {
        $options = array_column($field->options ?? [], 'label', 'value');

        return match (true) {
            $value === null => '',
            $field->type === 'boolean', $field->type === 'formula' && is_bool($value) => __('core.custom_field.yes_no.'.($value ? 'yes' : 'no')),
            $field->type === 'select' => (string) ($options[$value] ?? $value),
            $field->type === 'multi_select' => implode(', ', array_map(fn ($v) => (string) ($options[$v] ?? $v), (array) $value)),
            $field->type === 'money' => $value['currency'].' '.Money::ofMinor((string) $value['amount_minor'], (string) $value['currency'])->toDecimalString(),
            $field->type === 'lookup' => (string) ($value['label'] ?? ''),
            $field->type === 'file' => (string) ($value['name'] ?? ''),
            default => (string) $value,
        };
    }

    private function label(string $target, string $id, User $user): ?string
    {
        $lookup = $this->entities->lookup($target);

        if ($lookup === null) {
            return null;
        }

        return RequestCache::remember("lookup.{$user->id}.{$target}.{$id}", fn () => $lookup->labels($user, [$id])[$id] ?? null);
    }

    private function file(string $id, ?User $user): ?array
    {
        $file = RequestCache::remember("file.{$id}", fn () => CustomFieldFile::query()->find($id));

        return $file === null ? null : $this->files->present($file, $user);
    }
}
