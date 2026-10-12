<?php

namespace App\Core\MasterData\Parties;

use App\Core\CustomFields\CustomFieldWriter;
use App\Core\CustomFields\Entities\PartyEntity;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CreditLimits\CreditLimitChanges;
use App\Core\MasterData\Parties\Http\Requests\PartyRules;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-01: creates and changes parties from validated input (PartyRules),
 * for the API (PartyController) and imports (IMP-01) alike, under the
 * sharing locks of the party's roles (TEN-08). Audited as `core.party.*`
 * (MD-07).
 */
class PartyWriter
{
    public function __construct(
        private readonly MasterDataSharing $sharing,
        private readonly CustomFieldWriter $customFields,
    ) {}

    public function create(array $data): Party
    {
        $companyId = PartyRules::companyId($data, null) ?: null;
        $attributes = PartyRules::attributes($data, $companyId);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($attributes, $companyId, $data) {
            $this->sharing->lockForWrite(PartyRoles::dataTypes($attributes['roles']));
            $this->assertModeUnchanged($attributes['roles'], $companyId);

            $party = new Party(['company_id' => $companyId, ...$attributes]);
            // CF-01, CF-02: defaults, formulas and unique fields, then the party's files.
            $this->customFields->fill(PartyEntity::KEY, $party, $data['custom'] ?? null, creating: true);
            $party->save();
            $this->customFields->saved(PartyEntity::KEY, $party);

            return $party;
        });
    }

    public function update(Party $party, array $data, User $user): Party
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($party, $data, $user) {
            // Lock order, as in MasterDataSharing::switch: the sharing locks
            // first (every party type: the stored roles are only known once
            // the row is read), then the row. Never the other way round.
            $this->sharing->lockForWrite(PartyRoles::PARTY_DATA_TYPES);
            $party = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            $companyId = PartyRules::companyId($data, $party) ?: null;
            $attributes = PartyRules::attributes($data, $companyId);
            $this->assertModeUnchanged($attributes['roles'] ?? $party->roles, $companyId);

            // M1, WF-01: the credit limit rule again, against the locked row.
            if (array_key_exists('credit_limit', $data)) {
                app(CreditLimitChanges::class)->assertDirectChange($user, $party->creditLimit(), CreditLimitChanges::limitFrom($data), [$party->company_id, $companyId]);
            }

            $party->fill(['company_id' => $companyId, ...$attributes]);
            $this->customFields->fill(PartyEntity::KEY, $party, $data['custom'] ?? null, creating: false);
            $party->save();
            $this->customFields->saved(PartyEntity::KEY, $party);

            return $party;
        });
    }

    /**
     * Re-checked under the sharing lock: the mode may have changed since
     * the request was validated (TEN-08).
     *
     * @param  list<string>  $roles
     */
    private function assertModeUnchanged(array $roles, ?string $companyId): void
    {
        if (PartyRoles::perCompany($roles, $this->sharing) !== ($companyId !== null)) {
            throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
        }
    }
}
