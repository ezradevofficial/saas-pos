<?php

namespace Modules\Salon\Clients;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\Staff;
use Modules\Salon\Support\Errors;
use Modules\Salon\Support\Parties;

/**
 * SAL-25: the salon's profile of a customer party: preferences, allergies, formulas and a preferred staff member.
 * Allergies and formulas are written only by someone with `salon.client.notes` (the request checks), are encrypted at
 * rest and are audited by field name, never by value (NFR-08). A profile exists on its own once saved; a client with no
 * profile reads as an empty one.
 */
class Clients
{
    public const FIELDS = ['preferred_staff_id', 'preferences', 'allergies', 'formulas', 'notes'];

    /** Fields only `salon.client.notes` may read or write. */
    public const PRIVATE = ['allergies', 'formulas'];

    public function __construct(private readonly Auditor $auditor, private readonly Parties $parties) {}

    public function profile(Party $party): ?Client
    {
        return Client::query()->where('party_id', $party->id)->first();
    }

    /**
     * @param  array<string, mixed>  $data  any of FIELDS
     * @param  bool  $notes  whether the writer may set the private fields
     */
    public function save(Party $party, string $companyId, array $data, User $by, bool $notes): Client
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($party, $companyId, $data, $by, $notes) {
            $client = Client::query()->where('party_id', $party->id)->lockForUpdate()->first() ?? new Client(['company_id' => $companyId, 'party_id' => $party->id, 'created_by' => $by->id]);
            $allowed = array_values(array_diff(self::FIELDS, $notes ? [] : self::PRIVATE));
            $values = array_intersect_key($data, array_flip($allowed));

            if (isset($values['preferred_staff_id']) && ! Staff::query()->where('company_id', $companyId)->whereNull('archived_at')->whereKey($values['preferred_staff_id'])->exists()) {
                throw Errors::make(422, 'staff_unknown', field: 'preferred_staff_id');
            }

            $client->fill($values);
            $changed = array_keys($client->getDirty());
            $existed = $client->exists;
            $client->save();

            if ($changed !== [] || ! $existed) {
                // Which fields changed, never what they say.
                $this->auditor->record($existed ? 'salon.client.update' : 'salon.client.create', $client, null, ['fields' => array_values(array_diff($changed, ['company_id', 'party_id', 'created_by']))]);
            }

            return $client->refresh();
        });
    }

    /** A customer for a walk-in who gave a name and maybe a phone: made through the core party writer. */
    public function party(string $companyId, string $name, ?string $phone, ?string $email): Party
    {
        return $this->parties->make($companyId, ['name' => $name, 'phone' => $phone, 'email' => $email]);
    }
}
