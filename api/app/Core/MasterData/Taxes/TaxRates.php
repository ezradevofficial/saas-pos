<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Http\ApiException;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated rates of a tax code (CP-02). A new rate starts after the
 * latest one and closes it the day before; a rate dated on or before the
 * latest start is refused (422 `tax_rate_overlap`), so history is never
 * rewritten. One exception: the latest rate still needed (NULL) is filled
 * in for its own start date, since nothing can have been taxed with it.
 */
class TaxRates
{
    public function add(TaxCode $code, string $rate, CarbonImmutable $from): TaxRate
    {
        if ($code->isExempt()) {
            throw new ApiException(422, 'tax_code_exempt', __('core.tax.exempt_has_no_rate'));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($code, $rate, $from) {
            // Serialise rate changes of this code.
            TaxCode::query()->whereKey($code->id)->lockForUpdate()->firstOrFail();

            $rate = TaxCode::normaliseRate($rate);
            $day = $from->toDateString();
            $latest = TaxRate::query()->where('tax_code_id', $code->id)->orderByDesc('effective_from')->first();

            if ($latest !== null && $latest->effective_from->toDateString() === $day && $latest->rate === null) {
                $latest->fill(['rate' => $rate, 'needs_confirmation' => false])->save();

                return $latest;
            }

            if ($latest !== null && $latest->effective_from->toDateString() >= $day) {
                throw new ApiException(422, 'tax_rate_overlap', __('core.tax.rate_overlap', ['date' => $latest->effective_from->toDateString()]));
            }

            if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->toDateString() >= $day)) {
                $latest->effective_to = $from->subDay()->toDateString();
                $latest->save();
            }

            return TaxRate::create([
                'tax_code_id' => $code->id,
                'rate' => $rate,
                'effective_from' => $day,
                'effective_to' => null,
                'needs_confirmation' => false,
            ]);
        });
    }
}
