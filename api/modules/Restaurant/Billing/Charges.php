<?php

namespace Modules\Restaurant\Billing;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Modules\POS\Sync\Sellability;
use Modules\Restaurant\Models\Setting;

/**
 * REST-13: a company's service charge as Bill wants it: the percentage and
 * the tax rate of the service item (its tax category's code for the
 * company, in force now), read through POS's Sellability so rates are
 * never guessed (a missing or unconfirmed rate is no tax here and the till
 * refuses to ring the sale, POS-11).
 */
class Charges
{
    /** @var array<string, array{percent: ?string, tax_rate: ?string, service_item_id: ?string, tip_item_id: ?string, tip_presets: list<int>}> */
    private array $cache = [];

    public function __construct(private readonly Sellability $sellability) {}

    /** @return array{percent: ?string, tax_rate: ?string, service_item_id: ?string, tip_item_id: ?string, tip_presets: list<int>} */
    public function for(string $companyId): array
    {
        if (isset($this->cache[$companyId])) {
            return $this->cache[$companyId];
        }

        $setting = Setting::query()->where('company_id', $companyId)->first();
        $rate = null;

        if ($setting?->service_charge_item_id !== null) {
            $item = Item::query()->find($setting->service_charge_item_id);
            $company = Company::query()->find($companyId);
            $code = $item !== null && $company !== null ? $this->sellability->taxCode($item, $company) : null;

            if ($code !== null && ! $code->isExempt() && $code->kind !== 'withholding') {
                $found = $code->rateOn(CarbonImmutable::now());
                $rate = $found === null || $found->isNeeded() ? null : (string) $found->rate;
            }
        }

        return $this->cache[$companyId] = [
            'percent' => $setting === null ? null : (string) $setting->service_charge_percent,
            'tax_rate' => $rate,
            'service_item_id' => $setting?->service_charge_item_id,
            'tip_item_id' => $setting?->tip_item_id,
            'tip_presets' => array_values(array_map('intval', $setting?->tip_presets ?? [])),
        ];
    }

    /** @return array{percent: ?string, tax_rate: ?string} */
    public function service(string $companyId): array
    {
        $all = $this->for($companyId);

        return ['percent' => $all['percent'], 'tax_rate' => $all['tax_rate']];
    }
}
