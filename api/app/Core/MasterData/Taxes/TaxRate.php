<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Audited;
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
 * tenant entered. Audited as `core.tax_rate.*`.
 */
class TaxRate extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const SOURCE_PACK = 'pack';

    public const SOURCE_TENANT = 'tenant';

    protected $fillable = ['tax_code_id', 'rate', 'effective_from', 'effective_to', 'needs_confirmation', 'source'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'needs_confirmation' => 'boolean',
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

    public function scope(): Scope
    {
        return Scope::company($this->taxCode->company_id);
    }

    /** @return array{id: string, rate: ?string, effective_from: string, effective_to: ?string, needs_confirmation: bool, source: string} */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'rate' => $this->rate,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'needs_confirmation' => $this->needs_confirmation,
            'source' => $this->source,
        ];
    }
}
