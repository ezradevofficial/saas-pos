<?php

namespace App\Core\MasterData\Parties;

use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Sharing\SharedRecords;
use App\Core\MasterData\Support\TextArray;
use Illuminate\Database\Eloquent\Builder;

/**
 * TEN-08: the parties that follow one data type (customers: customer and
 * contact roles; suppliers; employees: employee links). A party with roles
 * of several types is per company while any of them is: releasing one type
 * keeps the company of parties another per-company type still holds.
 */
class PartySharedRecords implements SharedRecords
{
    public function __construct(
        private readonly string $dataType,
        private readonly MasterDataSharing $sharing,
    ) {}

    public function unassignedCount(): int
    {
        return $this->ofType()->whereNull('company_id')->count();
    }

    public function assignTo(string $companyId): int
    {
        $count = 0;

        $this->ofType()->whereNull('company_id')->lazyById()->each(function (Party $party) use ($companyId, &$count) {
            $party->company_id = $companyId;
            $party->save();
            $count++;
        });

        return $count;
    }

    public function release(): int
    {
        $count = 0;

        $this->ofType()->whereNotNull('company_id')->lazyById()->each(function (Party $party) use (&$count) {
            if (! PartyRoles::perCompany($party->roles, $this->sharing)) {
                $party->company_id = null;
                $party->save();
                $count++;
            }
        });

        return $count;
    }

    private function ofType(): Builder
    {
        return Party::query()->whereRaw('roles && ?::text[]', [TextArray::format(PartyRoles::of($this->dataType))]);
    }
}
