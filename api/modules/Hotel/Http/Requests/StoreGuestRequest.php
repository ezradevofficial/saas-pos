<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Validation\Rule;

/**
 * POST hotel/guests (HOT-16): `hotel.guest.manage` at the company. `{company_id, name, phone?, email?, nationality?, id_type?, id_number?,
 * date_of_birth?, address?, notes?, party_id?}`; a `party_id` links an existing customer. Field rules (RBAC-05, resource `hotel_guest`) refuse
 * input for fields hidden from or read-only for the user (422 `field_readonly`).
 */
class StoreGuestRequest extends HotelRequest
{
    use GuardsFieldRules;

    protected string $fieldRulesResource = 'hotel_guest';

    protected array $fieldRulesInputs = ['id_number' => ['identity_document'], 'id_type' => ['identity_document'], 'phone' => ['contact'], 'email' => ['contact']];

    protected function permission(): string
    {
        return 'hotel.guest.manage';
    }

    protected function companies(): array
    {
        return ['company_id' => 'hotel.guest.manage'];
    }

    public function rules(): array
    {
        return [
            'company_id' => HotelRules::company(),
            'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            ...GuestFields::rules(),
        ];
    }
}
