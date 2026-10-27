<?php

namespace Modules\Salon\Support;

use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Parties\PartyRoles;
use App\Core\MasterData\Parties\PartyWriter;
use App\Core\MasterData\Sharing\MasterDataSharing;
use Illuminate\Support\Str;

/**
 * Clients are core customer parties. Every id a request or a device names is read under the tenant (another tenant's
 * party does not exist) and must be a customer of the company (or shared), never a supplier or a stranger's.
 */
class Parties
{
    public function __construct(private readonly PartyWriter $writer, private readonly MasterDataSharing $sharing) {}

    public function customer(?string $id, string $companyId, string $field = 'client_id'): Party
    {
        $party = $id !== null && Str::isUuid($id) ? Party::query()->find($id) : null;

        if ($party === null || $party->archived_at !== null || ! in_array('customer', (array) $party->roles, true) || ($party->company_id !== null && $party->company_id !== $companyId)) {
            throw Errors::make(422, 'client_unknown', field: $field);
        }

        return $party;
    }

    /** @param array{name: string, phone?: ?string, email?: ?string} $data */
    public function make(string $companyId, array $data): Party
    {
        $perCompany = PartyRoles::perCompany(['customer'], $this->sharing);

        return $this->writer->create(array_filter([
            'company_id' => $perCompany ? $companyId : null,
            'kind' => 'person',
            'name' => $data['name'],
            'roles' => ['customer'],
            'phones' => ! empty($data['phone']) ? [['number' => $data['phone'], 'label' => 'mobile']] : null,
            'emails' => ! empty($data['email']) ? [['address' => $data['email'], 'label' => 'main']] : null,
        ], fn ($value) => $value !== null));
    }
}
