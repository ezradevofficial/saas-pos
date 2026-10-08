<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's tax code (MD-03): VAT, withholding, excise, exempt or
 * zero-rated, with effective-dated rates (CP-02). Copied from the
 * company's country pack (`pack_code`) or created by the tenant. Archived,
 * never deleted. Audited as `core.tax_code.*`.
 */
class TaxCode extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const KINDS = ['vat', 'withholding', 'excise', 'exempt', 'zero_rated'];

    protected $fillable = ['company_id', 'code', 'name_en', 'name_fr', 'kind', 'pack_code', 'fiscal_code'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('effective_from');
    }

    /** The rate row in force on $date (dates are inclusive), or null. Uses loaded rates when present. */
    public function rateOn(CarbonInterface $date): ?TaxRate
    {
        $day = $date->toDateString();
        $rates = $this->relationLoaded('rates') ? $this->rates : $this->rates()->get();

        return $rates
            ->filter(fn (TaxRate $rate) => $rate->effective_from->toDateString() <= $day
                && ($rate->effective_to === null || $rate->effective_to->toDateString() >= $day))
            ->sortByDesc(fn (TaxRate $rate) => $rate->effective_from->toDateString())
            ->first();
    }

    public function isExempt(): bool
    {
        return $this->kind === 'exempt';
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'fr' ? $this->name_fr : $this->name_en;
    }

    /** A percentage as stored, numeric(9,4): "16" => "16.0000". */
    public static function normaliseRate(string $rate): string
    {
        return (string) BigDecimal::of($rate)->toScale(4, RoundingMode::Unnecessary);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
