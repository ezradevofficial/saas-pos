<?php

namespace App\Core\MasterData\Parties;

use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Sharing\SharedRecords;
use App\Core\MasterData\Support\TextArray;
use App\Core\MasterData\Taxes\PriceList;
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

    /**
     * A party's price list belongs to one company: one of another company
     * than the party's new one is cleared (counted as price_lists_cleared).
     */
    public function assignTo(string $companyId): array
    {
        $counts = ['assigned' => 0, 'price_lists_cleared' => 0];

        $this->ofType()->whereNull('company_id')->lazyById()->each(function (Party $party) use ($companyId, &$counts) {
            $party->company_id = $companyId;

            if ($party->price_list_id !== null && PriceList::query()->whereKey($party->price_list_id)->value('company_id') !== $companyId) {
                $party->price_list_id = null;
                $counts['price_lists_cleared']++;
            }

            $party->save();
            $counts['assigned']++;
        });

        return $counts;
    }

    public function release(): array
    {
        $count = 0;

        $this->ofType()->whereNotNull('company_id')->lazyById()->each(function (Party $party) use (&$count) {
            if (! PartyRoles::perCompany($party->roles, $this->sharing)) {
                $party->company_id = null;
                $party->save();
                $count++;
            }
        });

        return ['released' => $count];
    }

    private function ofType(): Builder
    {
        return Party::query()->whereRaw('roles && ?::text[]', [TextArray::format(PartyRoles::of($this->dataType))]);
    }
}
