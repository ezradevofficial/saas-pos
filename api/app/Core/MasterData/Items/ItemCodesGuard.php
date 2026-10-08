<?php

namespace App\Core\MasterData\Items;

use App\Core\Http\ApiException;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Sharing\SharingSwitchGuard;
use Illuminate\Support\Facades\DB;

/**
 * TEN-08, review focus 4: sharing items across the group puts every
 * company's active items in one scope, where codes (case-insensitive) and
 * barcodes must be unique. A switch to shared that would make two active
 * items share one is refused with 422 `duplicate_codes`, listing them (at
 * most 50 of each). Per company to shared is the only direction to check:
 * splitting shared items gives every company a subset of unique values.
 */
class ItemCodesGuard implements SharingSwitchGuard
{
    private const LIMIT = 50;

    public function check(string $dataType, string $to): void
    {
        if ($dataType !== ItemSharing::DATA_TYPE || $to !== MasterDataSharing::SHARED) {
            return;
        }

        // citext groups codes case-insensitively; min() gives one spelling.
        $codes = DB::table('items')->whereNull('archived_at')
            ->groupBy('code')->havingRaw('count(*) > 1')
            ->orderByRaw('min(code::text)')->limit(self::LIMIT)
            ->selectRaw('min(code::text) as code')->pluck('code')->all();

        $barcodes = DB::table('item_barcodes')->whereNull('item_archived_at')
            ->groupBy('barcode')->havingRaw('count(*) > 1')
            ->orderBy('barcode')->limit(self::LIMIT)->pluck('barcode')->all();

        if ($codes !== [] || $barcodes !== []) {
            throw new ApiException(422, 'duplicate_codes', __('core.item.duplicate_codes'), extra: [
                'codes' => $codes,
                'barcodes' => $barcodes,
            ]);
        }
    }
}
