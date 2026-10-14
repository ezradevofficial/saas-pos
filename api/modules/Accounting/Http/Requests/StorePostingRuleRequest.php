<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Validation\Rule;

/** POST companies/{company}/accounting/posting-rules: the rule of an event type (ACC-07); it replaces the active one. */
class StorePostingRuleRequest extends AccountingRequest
{
    protected function permission(): string
    {
        return 'accounting.posting_rule.manage';
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*\z/'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            // ACC-24: `prefix.*` takes every `prefix.{key}` field, to the account its map names for the key, else its default.
            'lines.*.field' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*(\.\*)?\z/'],
            'lines.*.side' => ['required', 'string', Rule::in(['debit', 'credit'])],
            'lines.*.account_id' => ['nullable', 'uuid', $this->ofCompany('acc_accounts')],
            'lines.*.party' => ['sometimes', 'boolean'],
            'lines.*.by' => ['sometimes', 'nullable', 'string', Rule::in(['payment_method'])],
            'lines.*.accounts' => ['sometimes', 'nullable', 'array', 'max:200'],
            'lines.*.accounts.*' => ['uuid', $this->ofCompany('acc_accounts')],
        ];
    }
}
