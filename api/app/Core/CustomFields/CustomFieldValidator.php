<?php

namespace App\Core\CustomFields;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * CF-01, CF-02, RBAC-05: server-side checks of a record's `custom` input,
 * shared by the Form Requests of every entity (call it from `after()`):
 *
 *   CustomFieldValidator::rules()              'custom' => ['sometimes', 'array']
 *   app(CustomFieldValidator::class)->validate($validator, 'item', $input, $item, $user)
 *
 * - Only the keys given change; null clears a value. Unknown keys and
 *   archived fields are refused.
 * - A key the user may not see or change (CustomFieldAccess), and any
 *   formula field, is refused as a whole request: 422 `field_readonly`
 *   naming `custom.<key>`, as GuardsFieldRules refuses built-in fields.
 * - Types, min/max, pattern and options (CustomFieldValues); a lookup must
 *   name a record the user may see (TEN-01, RBAC-04) unless it is the value
 *   already stored; a file must be one uploaded for this field, by this user
 *   or already this record's.
 * - Required: on create, every editable required field without a default
 *   needs a value; on update, a required field given must not be cleared
 *   (records saved before the field existed can still be changed).
 * - Unique: checked here for a clear message, and again under a lock when
 *   saving (CustomFieldWriter), which is the real guard.
 *
 * Errors are keyed `custom.<key>` and use the field's label.
 */
class CustomFieldValidator
{
    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldEntities $entities,
        private readonly CustomFieldAccess $access,
        private readonly CustomFieldValues $values,
    ) {}

    /** @return array<string, list<string>> the rules a Form Request adds for `custom` */
    public static function rules(): array
    {
        return ['custom' => ['sometimes', 'nullable', 'array']];
    }

    public function validate(Validator $validator, string $entity, mixed $input, ?Model $record, ?User $user): void
    {
        $input = is_array($input) ? $input : [];
        $active = $this->definitions->byKey($entity);
        $access = $this->access->for($user, $entity);
        $this->refuseRestricted($input, $access);

        $known = $this->definitions->all($entity)->keyBy('key');
        $stored = $record === null ? [] : (array) ($record->custom ?? []);

        foreach ($input as $key => $value) {
            $key = (string) $key;
            $field = $active[$key] ?? null;

            if ($field === null) {
                $validator->errors()->add("custom.{$key}", __($known->has($key) ? 'core.custom_field.errors.archived' : 'core.custom_field.errors.unknown', ['field' => $key]));

                continue;
            }

            $normalised = $this->values->normalise($field, $value);

            if ($normalised instanceof CustomFieldError) {
                $validator->errors()->add("custom.{$key}", $normalised->message($field));

                continue;
            }

            if ($normalised === null) {
                if ($field->required) {
                    $validator->errors()->add("custom.{$key}", (new CustomFieldError('required'))->message($field));
                }

                continue;
            }

            $error = match ($field->type) {
                'lookup' => $this->lookup($field, $normalised, $stored[$key] ?? null, $user),
                'file' => $this->file($field, $normalised, $record, $user),
                default => null,
            };

            if ($error === null && $field->is_unique && ($stored[$key] ?? null) !== $normalised && app(CustomFieldWriter::class)->taken($entity, $key, $normalised, $record?->getKey())) {
                $error = new CustomFieldError('unique');
            }

            if ($error !== null) {
                $validator->errors()->add("custom.{$key}", $error->message($field));
            }
        }

        if ($record === null) {
            foreach ($active as $key => $field) {
                if ($field->required && ! array_key_exists($key, $input) && $field->default_value === null
                    && ! in_array($key, $access['hidden'], true) && ! in_array($key, $access['readonly'], true)) {
                    $validator->errors()->add("custom.{$key}", (new CustomFieldError('required'))->message($field));
                }
            }
        }
    }

    /**
     * RBAC-05: a key the user can't see or change refuses the whole write.
     *
     * @param  array{hidden: list<string>, readonly: list<string>}  $access
     */
    private function refuseRestricted(array $input, array $access): void
    {
        $restricted = [...$access['hidden'], ...$access['readonly']];
        $refused = [];

        foreach (array_keys($input) as $key) {
            if (in_array((string) $key, $restricted, true)) {
                $refused["custom.{$key}"] = [__('rbac.errors.field_readonly', ['field' => "custom.{$key}"])];
            }
        }

        if ($refused !== []) {
            throw new ApiException(422, 'field_readonly', reset($refused)[0], $refused);
        }
    }

    private function lookup(CustomFieldDefinition $field, string $id, mixed $stored, ?User $user): ?CustomFieldError
    {
        // The value already stored stays, even if someone else picked it.
        if ($stored === $id) {
            return null;
        }

        $target = $this->entities->lookup((string) $field->lookup_target);

        if ($target === null || $user === null || ! array_key_exists($id, $target->labels($user, [$id]))) {
            return new CustomFieldError('lookup_unknown');
        }

        return null;
    }

    private function file(CustomFieldDefinition $field, string $id, ?Model $record, ?User $user): ?CustomFieldError
    {
        $file = CustomFieldFile::query()->whereKey($id)->where('entity', $field->entity)->where('field_key', $field->key)->first();
        $ok = $file !== null && (
            ($file->record_id === null && $file->uploaded_by === $user?->id)
            || ($record !== null && $file->record_id === (string) $record->getKey())
        );

        return $ok ? null : new CustomFieldError('file_unknown');
    }
}
