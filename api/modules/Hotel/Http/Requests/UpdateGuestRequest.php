<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * PATCH hotel/guests/{hotel_guest} (HOT-16): `hotel.guest.manage` at the guest's company; changing the ID number, ID type, date of birth
 * or address also needs `hotel.guest.identity`. Field rules apply as on create.
 */
class UpdateGuestRequest extends HotelRequest
{
    use GuardsFieldRules;

    protected string $fieldRulesResource = 'hotel_guest';

    protected array $fieldRulesInputs = ['id_number' => ['identity_document'], 'id_type' => ['identity_document'], 'phone' => ['contact'], 'email' => ['contact']];

    protected function permission(): string
    {
        return 'hotel.guest.manage';
    }

    protected function companyId(): ?string
    {
        return $this->bound('hotel_guest')?->company_id;
    }

    public function rules(): array
    {
        return GuestFields::rules(updating: true);
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sensitive = array_intersect(['id_number', 'id_type', 'date_of_birth', 'address'], array_keys($this->all()));

            if ($sensitive !== [] && ! app(Access::class)->canCompany($this->user(), 'hotel.guest.identity', $this->bound('hotel_guest')->company_id)) {
                $validator->errors()->add(reset($sensitive), __('hotel.errors.identity_forbidden'));
            }
        }];
    }
}
