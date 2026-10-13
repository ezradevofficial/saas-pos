<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\Imports\ImportLimits;
use App\Core\Imports\ImportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * IMP-01: upload a file for an importer the user may run: CSV (UTF-8,
 * with or without a BOM) or XLSX, up to 20 MB (IMP-03). Rate-limited per
 * user (the `imports` limiter).
 */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $importer = app(ImportRegistry::class)->find((string) $this->input('importer'));

        // An unknown importer fails validation; a known one the user may not run is forbidden.
        return $importer === null || $importer->canRun($this->user());
    }

    public function rules(): array
    {
        return [
            'importer' => ['required', 'string', Rule::in(app(ImportRegistry::class)->keys())],
            'file' => [
                'required', 'file', 'max:'.ImportLimits::MAX_KB, 'extensions:csv,xlsx',
                'mimetypes:text/plain,text/csv,application/csv,text/x-csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            ],
        ];
    }

    public function fileFormat(): string
    {
        return strtolower($this->file('file')->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
    }
}
