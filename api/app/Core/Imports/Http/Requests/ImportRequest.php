<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\Imports\Import;
use App\Core\Imports\ImportAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * IMP-02: one import. Not seen (the importer is not the user's to run):
 * 404; seen without this request's ability (ImportAccess): 403. Another
 * tenant's import is never found (RLS, TEN-01).
 */
class ImportRequest extends FormRequest
{
    /** The ImportAccess ability: view, change, undo. */
    protected string $ability = 'view';

    public function authorize(): bool
    {
        $access = app(ImportAccess::class);

        abort_unless($access->view($this->user(), $this->import()), 404);

        return $access->{$this->ability}($this->user(), $this->import());
    }

    public function rules(): array
    {
        return [];
    }

    public function import(): Import
    {
        return $this->route('import');
    }
}
