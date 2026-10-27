<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/reservations (HOT-12): `hotel.reservation.create` at the branch.
 * `{branch_id, kind: direct|group, guest_id | guest: {...}, group_name? (required for a group), source?, notes?, master_folio?, stays: [{room_type_id,
 * rate_plan_id, arrival_on, departure_on, adults?, children?, rooms? (group only), guest_id?, notes?, bill_to_master?, rate_minor?, rate_reason?}]}`.
 * A direct booking has one stay. A typed `rate_minor` (a rate override, HOT-10) needs `hotel.rate.override` and a reason. Availability is
 * decided inside the booking, under the locks (HOT-13), not here.
 */
class StoreReservationRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.create';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.reservation.create'];
    }

    public function rules(): array
    {
        return [
            'branch_id' => HotelRules::branch(),
            'kind' => ['sometimes', 'string', Rule::in([Reservation::DIRECT, Reservation::GROUP])],
            'guest_id' => ['required_without:guest', 'uuid', Rule::exists('hotel_guests', 'id')->whereNull('archived_at')],
            'guest' => ['required_without:guest_id', 'array'],
            ...GuestFields::rules('guest.'),
            'group_name' => ['required_if:kind,group', 'nullable', 'string', 'max:120'],
            'source' => ['sometimes', 'string', Rule::in(Reservation::SOURCES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'master_folio' => ['sometimes', 'boolean'],
            ...StayFields::rules('stays.*.'),
            'stays' => ['required', 'array', 'min:1', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $access = app(Access::class);

            if (collect((array) $this->input('stays'))->contains(fn ($s) => isset($s['rate_minor'])) && ! $access->can($this->user(), 'hotel.rate.override', (string) $this->input('branch_id'))) {
                $validator->errors()->add('stays', __('hotel.errors.rate_override_forbidden'));
            }

            if ($this->filled('guest') && ! $access->canCompany($this->user(), 'hotel.guest.manage', (string) Branch::query()->whereKey($this->input('branch_id'))->value('company_id'))) {
                $validator->errors()->add('guest', __('hotel.errors.place_forbidden'));
            }
        }];
    }
}
