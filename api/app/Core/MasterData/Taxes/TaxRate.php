<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Audited;
use App\Core\CountryPacks\ResearchStatus;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One effective-dated rate of a tax code (CP-02), a percentage. NULL with
 * `needs_confirmation` when nobody has confirmed the figure ("Rate
 * needed"); a calculation needing it fails with `tax_rate_missing`.
 * Effective dates are inclusive; a new rate closes the previous one the day
 * before it starts. `source` is `pack` for rows copied from the country
 * pack (they follow its new versions, CP-03) and `tenant` for rows the
 * tenant entered. Audited as `core.tax_rate.*`. `research` (CP-01) is set
 * on a pack row whose rate comes from public research, not yet confirmed:
 * its confidence, research date and source URLs. Such a rate is used; the
 * tax lines computed with it say so (TaxAmount::$researched).
 */
class TaxRate extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const SOURCE_PACK = 'pack';

    public const SOURCE_TENANT = 'tenant';

    protected $fillable = ['tax_code_id', 'rate', 'effective_from', 'effective_to', 'needs_confirmation', 'source', 'research'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'needs_confirmation' => 'boolean',
            'research' => 'array',
        ];
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function isNeeded(): bool
    {
        return $this->rate === null || $this->needs_confirmation;
    }

    /** CP-01: the rate comes from public research and is not yet confirmed. */
    public function isResearched(): bool
    {
        return $this->research !== null && ! $this->isNeeded();
    }

    /** CP-01: researched at low confidence (the stronger notice). */
    public function isLowConfidence(): bool
    {
        return $this->isResearched() && ResearchStatus::lowResearch($this->research);
    }

    public function scope(): Scope
    {
        return Scope::company($this->taxCode->company_id);
    }

    /** @return array{id: string, rate: ?string, effective_from: string, effective_to: ?string, needs_confirmation: bool, source: string, research: ?array} */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'rate' => $this->rate,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'needs_confirmation' => $this->needs_confirmation,
            'source' => $this->source,
            'research' => $this->research,
        ];
    }
}
