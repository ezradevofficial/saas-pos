<?php

namespace App\Core\MasterData\Taxes;

use App\Core\CountryPacks\Models\CountryPack;
use App\Core\CountryPacks\Models\PackTaxCode;
use App\Core\Tenancy\Models\Company;

/**
 * Copies the company's country pack tax codes into the tenant (CP-01,
 * MD-03): on company creation and on `POST companies/{company}/tax-codes/apply-pack`.
 * Adds missing codes only: a pack code the company already has (archived
 * included) or whose code the tenant already uses is left alone, so tenant
 * edits are never overwritten. Rates are copied with their
 * needs_confirmation flags; exempt codes get no rate. Runs in the tenant's
 * context, inside the caller's transaction.
 */
class ApplyCountryPack
{
    /**
     * @return array{pack: ?CountryPack, added: list<string>, skipped: list<string>}
     */
    public function apply(Company $company): array
    {
        $pack = CountryPack::latest($company->country);

        if ($pack === null) {
            return ['pack' => null, 'added' => [], 'skipped' => []];
        }

        $existing = TaxCode::query()->where('company_id', $company->id)->get(['code', 'pack_code', 'archived_at']);
        $copied = $existing->pluck('pack_code')->filter()->all();
        $taken = $existing->whereNull('archived_at')->pluck('code')->all();

        $added = [];
        $skipped = [];

        foreach ($pack->taxCodes()->get()->groupBy('code') as $packCode => $periods) {
            if (in_array($packCode, $copied, true)) {
                continue;
            }

            if (in_array($packCode, $taken, true)) {
                $skipped[] = $packCode;

                continue;
            }

            /** @var PackTaxCode $latest */
            $latest = $periods->sortBy(fn (PackTaxCode $row) => $row->effective_from->toDateString())->last();

            $code = TaxCode::create([
                'company_id' => $company->id,
                'code' => $packCode,
                'name_en' => $latest->name_en,
                'name_fr' => $latest->name_fr,
                'kind' => $latest->kind,
                'pack_code' => $packCode,
                'fiscal_code' => $latest->fiscal_code,
            ]);

            if (! $code->isExempt()) {
                foreach ($periods as $period) {
                    TaxRate::create([
                        'tax_code_id' => $code->id,
                        'rate' => $period->rate,
                        'effective_from' => $period->effective_from,
                        'effective_to' => $period->effective_to,
                        'needs_confirmation' => $period->rate === null || $period->needs_confirmation,
                    ]);
                }
            }

            $added[] = $packCode;
        }

        return ['pack' => $pack, 'added' => $added, 'skipped' => $skipped];
    }
}
