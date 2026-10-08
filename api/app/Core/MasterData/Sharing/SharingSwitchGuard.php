<?php

namespace App\Core\MasterData\Sharing;

use App\Core\Http\ApiException;

/**
 * TEN-08, review focus 4: a check run before a data type changes mode, in
 * the switch transaction. Items (MD-02) register one so codes and barcodes
 * stay unique within the new sharing scope (`duplicate_codes`).
 */
interface SharingSwitchGuard
{
    /**
     * @param  string  $dataType  items|customers|suppliers|employees
     * @param  string  $to  shared|per_company
     *
     * @throws ApiException when the switch must not happen (422)
     */
    public function check(string $dataType, string $to): void;
}
