<?php

namespace Modules\Hotel\Guests;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Parties\PartyWriter;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Support\HotelError;

/**
 * HOT-16: guest records. A guest is a customer party of the company (made
 * with the core PartyWriter, or an existing customer linked), plus identity
 * document fields kept here. Name, phone and email are copied to the guest
 * for the front desk; changing them changes the party too. The ID number is
 * encrypted at rest and never audited in clear.
 */
class Guests
{
    public function __construct(
        private readonly PartyWriter $parties,
        private readonly MasterDataSharing $sharing,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data name, phone?, email?, nationality?, id_type?, id_number?, date_of_birth?, address?, notes?, party_id? */
    public function create(string $companyId, array $data, User $by): Guest
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($companyId, $data, $by) {
            $party = isset($data['party_id']) ? $this->existingParty((string) $data['party_id'], $companyId) : $this->newParty($companyId, $data);

            if (Guest::query()->where('party_id', $party->id)->exists()) {
                throw HotelError::make(409, 'guest_exists', field: 'party_id');
            }

            return Guest::create([
                'company_id' => $companyId,
                'party_id' => $party->id,
                'name' => $data['name'] ?? $party->name,
                'phone' => $data['phone'] ?? ($party->phoneNumbers()[0] ?? null),
                'email' => $data['email'] ?? ($party->emails[0]['address'] ?? null),
                'nationality' => $data['nationality'] ?? null,
                'id_type' => $data['id_type'] ?? null,
                'id_number' => $data['id_number'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ])->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Guest $guest, array $data, User $by): Guest
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($guest, $data) {
            $guest->fill(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'nationality', 'id_type', 'id_number', 'date_of_birth', 'address', 'notes'])));
            $changed = array_keys($guest->getDirty());
            $guest->save();

            // Personal data changes are audited by field, never by value (NFR-08).
            if (array_intersect($changed, ['id_number', 'date_of_birth', 'address'])) {
                $this->auditor->record('hotel.guest.identity_change', $guest, null, ['fields' => array_values(array_intersect($changed, ['id_number', 'date_of_birth', 'address']))]);
            }

            return $guest->refresh();
        });
    }

    private function existingParty(string $partyId, string $companyId): Party
    {
        $party = Party::query()->find($partyId);

        if ($party === null || ! in_array('customer', (array) $party->roles, true) || ($party->company_id !== null && $party->company_id !== $companyId)) {
            throw HotelError::make(422, 'party_unknown', field: 'party_id');
        }

        return $party;
    }

    /** @param array<string, mixed> $data */
    private function newParty(string $companyId, array $data): Party
    {
        $perCompany = PartyRoles::perCompany(['customer'], $this->sharing);

        return $this->parties->create(array_filter([
            'company_id' => $perCompany ? $companyId : null,
            'kind' => 'person',
            'name' => $data['name'],
            'roles' => ['customer'],
            'phones' => ! empty($data['phone']) ? [['number' => $data['phone'], 'label' => 'mobile']] : null,
            'emails' => ! empty($data['email']) ? [['address' => $data['email'], 'label' => 'main']] : null,
        ], fn ($value) => $value !== null));
    }
}
