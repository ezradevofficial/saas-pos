<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\Imports\Http\Lists\ImportList;
use App\Core\Imports\Import;
use App\Core\Imports\ImportRegistry;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\CompanyReach;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET imports (`core.import.run`): the imports of the importers the user
 * may run; `?search=` (file name), `?importer=`, `?status=`, `?sort=`,
 * pages of `?per_page`, and an export (ImportList, EXP-01).
 */
class ListImportsRequest extends FormRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new ImportList;
    }

    public function authorize(): bool
    {
        return app(CompanyReach::class)->anywhere($this->user(), ['core.import.run']);
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'importer' => ['sometimes', 'string', Rule::in(app(ImportRegistry::class)->keys())],
            'status' => ['sometimes', 'string', Rule::in(Import::STATUSES)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
