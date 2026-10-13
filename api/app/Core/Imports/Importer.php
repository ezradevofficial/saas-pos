<?php

namespace App\Core\Imports;

use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use Illuminate\Database\Eloquent\Model;

/**
 * IMP-01: one kind of record a file can bring in. An importer declares:
 *
 * - its key, the record model, and the permissions it needs besides
 *   `core.import.run` (the entity's create permission, and edit when
 *   existing records are updated);
 * - its columns (type, required, help, en/fr labels) plus the entity's
 *   custom fields the user may change (`cf_<key>`, CF-03), and an example
 *   row for the template;
 * - how a row becomes the API body (`prepare`: lookups by code or name,
 *   the record it updates, matched by code or tax ID), which the API's
 *   own Form Request then validates (`validate`, DomainRequests), and the
 *   domain writer that saves it (`write`) with the before image;
 * - how a row is undone (`undo`: archive what was created, put back the
 *   before image) and what makes a created record depend on (RecordUsage,
 *   `usageEntity`).
 *
 * Importers for employees, opening stock and opening balances register
 * the same way once HR, Inventory and Accounting exist (ADR 014).
 */
abstract class Importer
{
    abstract public function key(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<ImportColumn> the built-in columns, in template order */
    abstract protected function baseColumns(): array;

    /** @return list<string> permissions needed besides core.import.run, per mode */
    abstract public function permissions(string $mode): array;

    /** @return list<string> the columns an existing record is matched by (update mode) */
    abstract public function matchColumns(): array;

    /** @return array<string, string> column key => the template's example value */
    abstract public function example(): array;

    /**
     * The API body for a row (values by column key, empty cells left out),
     * and the record it updates. Throws RowRejected (unknown unit,
     * existing code in create mode...).
     *
     * @param  array<string, mixed>  $values
     */
    abstract public function prepare(int $number, array $values, ImportContext $context): PreparedRow;

    /** Run the API's Form Request on the row; sets `$row->validated`. Throws RowRejected. */
    abstract public function validate(PreparedRow $row, ImportContext $context): void;

    /**
     * Save the row through the domain writer.
     *
     * @return array{record: Model, before: ?array<string, mixed>}
     */
    abstract public function write(PreparedRow $row, ImportContext $context): array;

    /** IMP-02: archive a created record, or put back an updated record's before image. */
    abstract public function undo(ImportRow $row, ImportContext $context): void;

    /** What a person calls the record (its code, name) in lists of blocking rows. */
    abstract public function describe(Model $record): string;

    /** @return array<string, string> input field => the column it comes from (errors name columns) */
    abstract protected function fieldColumns(): array;

    /** CF-03: the custom field entity whose fields are columns, or null. */
    public function customFieldEntity(): ?string
    {
        return null;
    }

    /** IMP-02: the RecordUsage entity whose uses block undoing a created record, or null. */
    public function usageEntity(): ?string
    {
        return null;
    }

    /**
     * Read-only domain checks a dry run adds to the Form Request (those the
     * writer makes under its locks, such as unique barcodes). Throws RowRejected.
     */
    public function check(PreparedRow $row, ImportContext $context): void {}

    /**
     * The row's identity, so a file naming the same record twice is caught
     * (the second row is refused), or null.
     *
     * @param  array<string, mixed>  $values
     */
    public function identity(array $values): ?string
    {
        return null;
    }

    public function label(): string
    {
        return __("imports.importers.{$this->key()}");
    }

    /** Whether $user may run this importer in $mode at all (the Form Requests check each row's scope). */
    public function canRun(User $user, string $mode = 'create'): bool
    {
        $reach = app(CompanyReach::class);

        if (! $reach->anywhere($user, ['core.import.run'])) {
            return false;
        }

        foreach ($this->permissions($mode) as $permission) {
            if (! $reach->anywhere($user, [$permission])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The columns for $user: the built-in ones, then the custom fields they
     * may change (not formulas or files, nothing hidden or read-only for
     * them, RBAC-05).
     *
     * @return list<ImportColumn>
     */
    public function columns(User $user): array
    {
        $columns = $this->baseColumns();

        foreach ($this->customFields($user) as $field) {
            $columns[] = new ImportColumn('cf_'.$field->key, 'custom:'.$field->type, false, label: $field->label, help: (string) ($field->help ?? ''));
        }

        return $columns;
    }

    /** @return array<string, CustomFieldDefinition> by column key (`cf_<key>`) */
    public function customFields(User $user): array
    {
        $entity = $this->customFieldEntity();

        if ($entity === null) {
            return [];
        }

        $access = app(CustomFieldAccess::class)->for($user, $entity);
        $restricted = [...$access['hidden'], ...$access['readonly']];
        $fields = [];

        foreach (app(CustomFieldDefinitions::class)->active($entity) as $field) {
            if (! in_array($field->type, ['formula', 'file'], true) && ! in_array($field->key, $restricted, true)) {
                $fields['cf_'.$field->key] = $field;
            }
        }

        return $fields;
    }

    /**
     * The column an error key names: `barcodes.0.barcode` => barcodes,
     * `custom.colour` => cf_colour; null for the row as a whole.
     */
    public function errorColumn(string $field): ?string
    {
        if ($field === '_') {
            return null;
        }

        $parts = explode('.', $field);

        if ($parts[0] === 'custom' && isset($parts[1])) {
            return 'cf_'.$parts[1];
        }

        return $this->fieldColumns()[$parts[0]] ?? null;
    }

    /**
     * The custom values among $values, as the API's `custom` input.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function customInput(array $values): array
    {
        $custom = [];

        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'cf_')) {
                $custom[substr($key, 3)] = $value;
            }
        }

        return $custom;
    }

    /**
     * The `custom` input that puts $before back: its values, and null for
     * the keys set since.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $now
     * @return array<string, mixed>
     */
    protected static function customRestore(array $before, array $now): array
    {
        return [...array_fill_keys(array_keys(array_diff_key($now, $before)), null), ...$before];
    }
}
