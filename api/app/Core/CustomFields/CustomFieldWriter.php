<?php

namespace App\Core\CustomFields;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * CF-01, CF-02, CF-06: writes validated `custom` input onto a record, in
 * the caller's transaction, before the record is saved:
 *
 *   $writer->fill('item', $item, $data['custom'] ?? null, creating: true);
 *   $item->save();
 *   $writer->saved('item', $item);
 *
 * - The keys given replace the stored ones (null clears); others stay.
 *   On create, fields not given take their default.
 * - Formula fields are computed from the other fields on every save.
 * - Unique fields: a transaction-level advisory lock per tenant, entity
 *   and field serialises writers of that field, then no active record
 *   (archived ones are left out) may hold the same value (422 `unique`).
 *   The values are found through the entity's GIN index (`custom @>`).
 * - Files named by the record are tied to it once it is saved.
 *
 * The record's audit entry carries the whole `custom` object before and
 * after (AUD-01); history hides the fields the reader can't see.
 */
class CustomFieldWriter
{
    public function __construct(
        private readonly CustomFieldDefinitions $definitions,
        private readonly CustomFieldValues $values,
        private readonly CustomFieldEntities $entities,
        private readonly CustomFieldFiles $files,
    ) {}

    /** @param array<string, mixed>|null $input the validated `custom` input, null when not given */
    public function fill(string $entity, Model $record, ?array $input, bool $creating): void
    {
        if (DB::connection(TenantContext::CONNECTION)->transactionLevel() === 0) {
            throw new LogicException('Custom field values are written inside a transaction (unique fields are locked until it ends).');
        }

        $fields = $this->definitions->active($entity);

        if ($fields->isEmpty() && $input === null) {
            return;
        }

        $custom = (array) ($record->custom ?? []);

        foreach ($fields as $field) {
            if ($creating && ! array_key_exists($field->key, $input ?? []) && $field->default_value !== null && $field->type !== 'formula') {
                $default = $this->values->normalise($field, $field->default_value);
                $custom[$field->key] = $default instanceof CustomFieldError ? null : $default;
            }
        }

        foreach ($input ?? [] as $key => $value) {
            $field = $fields->firstWhere('key', (string) $key);

            if ($field !== null && $field->type !== 'formula') {
                $normalised = $this->values->normalise($field, $value);
                $custom[$field->key] = $normalised instanceof CustomFieldError ? null : $normalised;
            }
        }

        $inputs = $this->values->formulaInputs($fields, $custom);

        foreach ($fields->where('type', 'formula') as $field) {
            $custom[$field->key] = $field->parsedFormula()?->evaluate($inputs, $field->formula_type ?? 'number');
        }

        $custom = array_filter($custom, fn ($value) => $value !== null);
        $this->assertUnique($entity, $fields->where('is_unique', true)->all(), $custom, $record->exists ? (string) $record->getKey() : null, (array) ($record->getOriginal('custom') ?? []));

        // An empty object, never an empty JSON list (the column holds an object).
        $record->custom = $custom === [] ? new \stdClass : $custom;
    }

    /** Tie the files the record names to it (after it is saved and has an id). */
    public function saved(string $entity, Model $record): void
    {
        $fileKeys = $this->definitions->all($entity)->where('type', 'file')->pluck('key')->all();
        $ids = array_values(array_filter(array_map(fn (string $key) => $record->custom[$key] ?? null, $fileKeys)));

        $this->files->attach($entity, (string) $record->getKey(), $ids);
    }

    /**
     * A restored record takes its unique values back: refused (422) while
     * active records hold them. Call inside the restore's transaction.
     */
    public function assertRestorable(string $entity, Model $record): void
    {
        $fields = $this->definitions->active($entity)->where('is_unique', true)->all();
        $this->assertUnique($entity, $fields, (array) ($record->custom ?? []), (string) $record->getKey(), []);
    }

    /** Whether an active record other than $exceptId holds $value in field $key. */
    public function taken(string $entity, string $key, mixed $value, ?string $exceptId): bool
    {
        return $this->entities->get($entity)->newQuery()
            ->whereNull('archived_at')
            ->whereRaw('custom @> ?::jsonb', [json_encode([$key => $value])])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * @param  array<int, CustomFieldDefinition>  $unique
     * @param  array<string, mixed>  $custom
     * @param  array<string, mixed>  $original
     */
    private function assertUnique(string $entity, array $unique, array $custom, ?string $recordId, array $original): void
    {
        $tenant = app(TenantContext::class)->require();
        $errors = [];

        foreach ($unique as $field) {
            $value = $custom[$field->key] ?? null;

            // Unchanged values were unique when saved; a duplicate made by an
            // earlier rule change is reported only when the value changes.
            if ($value === null || ($recordId !== null && ($original[$field->key] ?? null) === $value)) {
                continue;
            }

            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtext(?))', ["custom_field:{$tenant}:{$entity}:{$field->key}"]);

            if ($this->taken($entity, $field->key, $value, $recordId)) {
                $errors["custom.{$field->key}"] = [(new CustomFieldError('unique'))->message($field)];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
