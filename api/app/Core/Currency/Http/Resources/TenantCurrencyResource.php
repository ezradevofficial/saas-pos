<?php

namespace App\Core\Currency\Http\Resources;

use App\Core\Currency\Currencies;
use App\Core\Currency\CurrencyUsage;
use App\Core\Currency\Models\TenantCurrency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantCurrency */
class TenantCurrencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $catalogue = app(Currencies::class);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $catalogue->name($this->code),
            'decimals' => $this->decimals,
            'default_decimals' => $catalogue->find($this->code)['default_decimals'] ?? null,
            'decimals_locked' => app(CurrencyUsage::class)->isUsed($this->code),
            'cash_rounding_minor' => $this->cash_rounding_minor,
            'active' => $this->active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
