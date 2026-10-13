<?php

namespace App\Core\Imports\Http\Requests;

use App\Core\Imports\Import;
use App\Core\Imports\ImportMapper;
use App\Core\Imports\ImportRegistry;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * IMP-02: the column mapping (`{column key: header index}`) and the mode
 * (`create`, or `upsert`: create and update, matched by code or tax ID).
 * Every required column must be mapped, each header used once. Updating
 * needs the importer's edit permission too.
 */
class UpdateImportRequest extends ImportActionRequest
{
    public function rules(): array
    {
        return [
            'mapping' => ['sometimes', 'array'],
            'mapping.*' => ['integer', 'min:0'],
            'mode' => ['sometimes', 'string', Rule::in(Import::MODES)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $importer = app(ImportRegistry::class)->get($this->import()->importer);
            $mode = $this->validated('mode', $this->import()->mode);

            if (! $importer->canRun($this->user(), $mode)) {
                $validator->errors()->add('mode', __('imports.errors.mode_forbidden'));

                return;
            }

            $mapping = $this->has('mapping') ? $this->validated('mapping') : $this->import()->mapping;

            foreach (app(ImportMapper::class)->errors($importer, $this->user(), $this->import()->headers, $mapping) as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }];
    }
}
