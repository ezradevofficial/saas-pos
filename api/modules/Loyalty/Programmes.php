<?php

namespace Modules\Loyalty;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Tenancy\Models\Company;
use Modules\Loyalty\Models\Programme;

/**
 * LOY-01: a company's programme. Its currency is the company's base
 * currency when it is first saved (points are earned and valued in it);
 * until saved, the defaults below are shown and nothing is earned.
 */
class Programmes
{
    /** The defaults a company starts from (one point per 100 units of its currency, a point worth 1 unit). */
    public static function defaults(Company $company): array
    {
        $scale = 10 ** app(CurrencyDecimals::class)->for($company->base_currency);

        return [
            'active' => false,
            'currency' => $company->base_currency,
            'earn_points' => 1,
            'earn_spend_minor' => (string) (100 * $scale),
            'point_value_minor' => (string) $scale,
            'min_redeem_points' => '0',
            'expiry_months' => null,
            'expiry_notice_days' => 14,
            'credit_warning_percent' => 80,
            'gift_card_low_minor' => null,
            'gift_card_item_id' => null,
        ];
    }

    public function for(string $companyId): ?Programme
    {
        return Programme::query()->where('company_id', $companyId)->first();
    }

    /** The programme when it exists and is switched on. */
    public function active(string $companyId): ?Programme
    {
        $programme = $this->for($companyId);

        return $programme?->active ? $programme : null;
    }

    /** Save the company's programme (created with the defaults first); audited through the model. */
    public function save(Company $company, array $values): Programme
    {
        $programme = $this->for($company->id) ?? new Programme(['company_id' => $company->id, ...self::defaults($company)]);
        $programme->fill($values)->save();

        return $programme->refresh();
    }
}
