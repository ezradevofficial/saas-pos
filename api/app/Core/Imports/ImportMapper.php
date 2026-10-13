<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;

/**
 * IMP-02: which file column feeds which importer column. The guess
 * prefers the mapping last used for the importer in the tenant (by header
 * text), then matches headers against each column's key and its English
 * and French labels, ignoring case, accents, spaces and punctuation, so a
 * French template maps itself as an English one does.
 */
class ImportMapper
{
    /**
     * @param  list<string>  $headers
     * @return array<string, int> column key => header index
     */
    public function guess(Importer $importer, User $user, array $headers): array
    {
        $normalised = array_map(fn (string $header) => ImportCells::key($header), $headers);
        $saved = (array) (ImportMapping::query()->where('importer', $importer->key())->value('mapping') ?? []);
        $mapping = [];
        $taken = [];
        $columns = $importer->columns($user);

        $claim = function (string $key, string $name) use ($normalised, &$mapping, &$taken): bool {
            $wanted = ImportCells::key($name);

            foreach ($normalised as $index => $header) {
                if ($wanted !== '' && $header === $wanted && ! isset($taken[$index])) {
                    $mapping[$key] = $index;
                    $taken[$index] = true;

                    return true;
                }
            }

            return false;
        };

        foreach ($columns as $column) {
            if (is_string($saved[$column->key] ?? null)) {
                $claim($column->key, $saved[$column->key]);
            }
        }

        foreach ($columns as $column) {
            if (isset($mapping[$column->key])) {
                continue;
            }

            foreach ($column->headerNames() as $name) {
                if ($claim($column->key, $name)) {
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Errors in a mapping: unknown columns, a header used twice, headers
     * that don't exist, required columns left out.
     *
     * @param  array<string, mixed>  $mapping
     * @param  list<string>  $headers
     * @return array<string, list<string>>
     */
    public function errors(Importer $importer, User $user, array $headers, array $mapping): array
    {
        $columns = collect($importer->columns($user))->keyBy('key');
        $errors = [];
        $used = [];

        foreach ($mapping as $key => $index) {
            if (! $columns->has($key)) {
                $errors["mapping.{$key}"][] = __('imports.errors.unknown_column');
            } elseif (! is_int($index) || $index < 0 || $index >= count($headers)) {
                $errors["mapping.{$key}"][] = __('imports.errors.unknown_header');
            } elseif (isset($used[$index])) {
                $errors["mapping.{$key}"][] = __('imports.errors.header_twice', ['header' => $headers[$index]]);
            } else {
                $used[$index] = true;
            }
        }

        foreach ($columns as $key => $column) {
            if ($column->required && ! array_key_exists($key, $mapping)) {
                $errors["mapping.{$key}"][] = __('imports.errors.column_required', ['column' => $column->label()]);
            }
        }

        return $errors;
    }

    /** Keep the mapping for the next file of this importer (by header text). */
    public function remember(Import $import): void
    {
        $byHeader = [];

        foreach ((array) $import->mapping as $key => $index) {
            if (isset($import->headers[$index])) {
                $byHeader[$key] = $import->headers[$index];
            }
        }

        ImportMapping::query()->updateOrCreate(['importer' => $import->importer], ['mapping' => $byHeader]);
    }
}
