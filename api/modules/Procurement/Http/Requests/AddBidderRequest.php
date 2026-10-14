<?php

namespace Modules\Procurement\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * POST procurement/tenders/{id}/bidders (PRC-08): `procurement.tender.create`
 * where the tender is; `{party_id}` (a supplier, its first email unless one
 * is typed) or `{name, email, tax_id?}` (an outsider), `email?` either way.
 */
class AddBidderRequest extends ShowDocumentRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->mayAt('create');
    }

    public function rules(): array
    {
        return [
            'party_id' => ProcurementRules::id('parties'),
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->filled('party_id') && (! $this->filled('name') || ! $this->filled('email'))) {
                $validator->errors()->add('party_id', __('procurement.errors.bidder_party_or_contact'));
            }
        }];
    }
}
