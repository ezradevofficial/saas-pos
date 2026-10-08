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

    /**
     * The rate row in force at the instant $at (dates are inclusive), or
     * null. The day is $at's date in the company's time zone, so a sale
     * just after local midnight takes the new day's rate. Uses loaded rates
     * when present.
     */
    public function rateOn(CarbonInterface $at): ?TaxRate
    {
        $day = $this->localDate($at);
        $rates = $this->relationLoaded('rates') ? $this->rates : $this->rates()->get();

        return $rates
            ->filter(fn (TaxRate $rate) => $rate->effective_from->toDateString() <= $day
                && ($rate->effective_to === null || $rate->effective_to->toDateString() >= $day))
            ->sortByDesc(fn (TaxRate $rate) => $rate->effective_from->toDateString())
            ->first();
    }

    /** $at's date (Y-m-d) in the company's time zone (UTC when unknown). */
    public function localDate(CarbonInterface $at): string
    {
        return $at->toImmutable()->setTimezone($this->company?->timezone ?: 'UTC')->toDateString();
    }

    public function isExempt(): bool
    {
        return $this->kind === 'exempt';
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'fr' ? $this->name_fr : $this->name_en;
    }

    /** A percentage as stored, numeric(9,4): "12.5" => "12.5000". */
    public static function normaliseRate(string $rate): string
    {
        return (string) BigDecimal::of($rate)->toScale(4, RoundingMode::Unnecessary);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
