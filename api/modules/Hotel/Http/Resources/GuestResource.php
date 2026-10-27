<?php

namespace Modules\Hotel\Http\Resources;

use App\Core\MasterData\Items\Http\Resources\HidesFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Support\Access;

/**
 * HOT-16, HOT-38: a guest. Personal data is masked by default: the ID number
 * shows its last four characters, and shows in full only to a reader with
 * `hotel.guest.identity` whose field rules (RBAC-05, resource `hotel_guest`)
 * do not hide `identity_document`; the date of birth and address follow
 * their own fields. Hidden fields are left out, not blanked.
 *
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    public const RESOURCE = 'hotel_guest';

    private bool $full = false;

    /** Show the identity document in full (the caller audited the access, HOT-38). */
    public function full(bool $full = true): self
    {
        $this->full = $full;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Guest $g */
        $g = $this->resource;
        $id = (string) $g->id_number;
        $mayIdentify = $request->user() !== null && app(Access::class)->canCompany($request->user(), 'hotel.guest.identity', $g->company_id);

        return HidesFields::apply($request, self::RESOURCE, [
            'id' => $g->id,
            'company_id' => $g->company_id,
            'party_id' => $g->party_id,
            'name' => $g->name,
            'phone' => $g->phone,
            'email' => $g->email,
            'nationality' => $g->nationality,
            'id_type' => $g->id_type,
            'identity_document' => $id === '' ? null : ($this->full && $mayIdentify ? $id : '•••• '.mb_substr($id, -4)),
            'has_identity_document' => $id !== '',
            'date_of_birth' => $mayIdentify ? Refs::day($g->date_of_birth) : null,
            'address' => $mayIdentify ? $g->address : null,
            'notes' => $g->notes,
            'archived_at' => Refs::at($g->archived_at),
            'created_at' => Refs::at($g->created_at),
        ], ['identity_document' => ['identity_document'], 'phone' => ['contact'], 'email' => ['contact']]);
    }
}
