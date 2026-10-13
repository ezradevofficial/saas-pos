<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\Imports\Importer;
use App\Core\Imports\ImportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** IMP-01: an importer's template, in English or French (`?locale=`, default the user's language). */
class ImportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_if($this->importer() === null, 404);

        return $this->importer()->canRun($this->user());
    }

    public function rules(): array
    {
        return ['locale' => ['sometimes', 'string', Rule::in(['en', 'fr'])]];
    }

    public function importer(): ?Importer
    {
        return app(ImportRegistry::class)->find((string) $this->route('importer'));
    }
}
