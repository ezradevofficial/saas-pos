<?php

namespace App\Core\Imports;

use App\Core\Exports\SpreadsheetCell;
use App\Core\Identity\Models\User;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * IMP-01: an importer's template in English or French: the column
 * headers in that language with an example row, and a notes sheet saying
 * what each column takes, which are required, how existing records are
 * matched and how files are read. Custom field columns (CF-03) carry
 * their labels as typed by the tenant.
 */
class ImportTemplates
{
    /** Writes the template to a temporary file; the caller streams and removes it. */
    public function write(Importer $importer, User $user, string $locale): string
    {
        $columns = $importer->columns($user);
        $example = $importer->example();
        $path = tempnam(sys_get_temp_dir(), 'import-template-');
        $bold = new Style(fontBold: true);
        $cell = fn (string $value, ?Style $style = null) => new StringCell(SpreadsheetCell::safe($value), $style);

        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(SpreadsheetCell::sheetName(__('imports.template.data_sheet', [], $locale)));
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        $writer->addRow(new Row(array_map(fn (ImportColumn $column) => $cell($column->label($locale), $bold), $columns)));
        $writer->addRow(new Row(array_map(fn (ImportColumn $column) => $cell($this->exampleValue($column, $example, $locale)), $columns)));

        $writer->addNewSheetAndMakeItCurrent()->setName(SpreadsheetCell::sheetName(__('imports.template.notes_sheet', [], $locale)));
        $writer->addRow(new Row([$cell(__('imports.template.title', ['importer' => __("imports.importers.{$importer->key()}", [], $locale)], $locale), $bold)]));

        foreach (['example_row', 'blank_cells', 'match', 'lists', 'numbers', 'dates', 'limits'] as $note) {
            $text = __("imports.template.notes.{$note}", [
                'columns' => implode(', ', array_map(fn (string $key) => $this->labelOf($columns, $key, $locale), $importer->matchColumns())),
                'rows' => number_format(ImportLimits::MAX_ROWS, 0, '.', $locale === 'fr' ? ' ' : ','),
                'megabytes' => ImportLimits::MAX_KB / 1024,
            ], $locale);
            $writer->addRow(new Row([$cell($text)]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow(new Row(array_map(fn (string $key) => $cell(__("imports.template.headings.{$key}", [], $locale), $bold), ['column', 'required', 'type', 'help', 'values'])));

        foreach ($columns as $column) {
            $writer->addRow(new Row([
                $cell($column->label($locale)),
                $cell(__($column->required ? 'imports.template.yes' : 'imports.template.no', [], $locale)),
                $cell(__('imports.types.'.$this->typeKey($column), [], $locale)),
                $cell($column->help($locale)),
                $cell(implode(', ', array_map(
                    fn (string $value) => $column->optionsKey === null ? $value : __("{$column->optionsKey}.{$value}", [], $locale),
                    $column->options,
                ))),
            ]));
        }

        $writer->close();

        return $path;
    }

    private function exampleValue(ImportColumn $column, array $example, string $locale): string
    {
        $value = $example[$column->key] ?? '';

        // Enums are shown by their label in the template's language.
        if ($value !== '' && $column->type === 'enum' && $column->optionsKey !== null) {
            return __("{$column->optionsKey}.{$value}", [], $locale);
        }

        return $value;
    }

    /** @param list<ImportColumn> $columns */
    private function labelOf(array $columns, string $key, string $locale): string
    {
        foreach ($columns as $column) {
            if ($column->key === $key) {
                return $column->label($locale);
            }
        }

        return __("imports.columns.{$key}", [], $locale);
    }

    private function typeKey(ImportColumn $column): string
    {
        return str_starts_with($column->type, 'custom:') ? 'custom_'.substr($column->type, 7) : $column->type;
    }
}
