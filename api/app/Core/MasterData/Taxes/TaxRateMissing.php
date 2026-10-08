<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Http\ApiException;

/**
 * A tax code has no confirmed rate on the date of a calculation (CP-02):
 * 422 `tax_rate_missing`, naming the code. Rates are never guessed.
 */
class TaxRateMissing extends ApiException
{
    public static function for(TaxCode $code, string $date): self
    {
        return new self(422, 'tax_rate_missing', __('core.tax.rate_missing', ['code' => $code->code, 'date' => $date]), extra: ['tax_code' => $code->code]);
    }
}
