<?php

namespace App\Core\Lists;

use App\Core\Exports\ExportValues;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One exportable column of a list (EXP-01). The value is read from the
 * array the list's API resource renders for the requesting user, so a
 * field hidden by field rules (RBAC-05) never reaches a file; `fields` are
 * the resource keys the column reads, and the column is dropped when any
 * of them is hidden.
 */
final class ListColumn
{
    /**
     * @param  string  $key  the `?columns[]=` key
     * @param  string  $label  translation key of the header (the header itself when $literal)
     * @param  list<string>  $fields  resource keys the value is built from
     * @param  Closure(array<string, mixed>, Model, ExportValues): ?string  $value
     * @param  bool  $literal  the label is tenant text printed as typed (a custom field's, CF-03)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $fields,
        public readonly Closure $value,
        public readonly bool $literal = false,
    ) {}

    /** The header as printed: translated, or the tenant's own text as typed. */
    public function header(): string
    {
        return $this->literal ? $this->label : __($this->label);
    }

    /** A column showing one resource key as text. */
    public static function text(string $key, string $label, ?string $field = null): self
    {
        $field ??= $key;

        return new self($key, $label, [$field], fn (array $row) => $row[$field] === null ? null : (string) $row[$field]);
    }

    /** `status`: Active or Archived, from an archivable record's `archived_at` (TEN-06). */
    public static function archiveStatus(string $label): self
    {
        return new self('status', $label, ['archived_at'], fn (array $row) => __('core.list.statuses.'.($row['archived_at'] === null ? 'active' : 'archived')));
    }

    /**
     * @param  Closure(array<string, mixed>, Model, ExportValues): ?string  $value
     * @param  list<string>  $fields
     */
    public static function make(string $key, string $label, array $fields, Closure $value): self
    {
        return new self($key, $label, $fields, $value);
    }

    /** @param array<string, mixed> $row */
    public function valueFor(array $row, Model $model, ExportValues $values): string
    {
        return (string) (($this->value)($row, $model, $values) ?? '');
    }
}
