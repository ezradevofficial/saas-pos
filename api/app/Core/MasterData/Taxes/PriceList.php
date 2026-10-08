<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's price list in one currency (MD-03): its prices include tax
 * or not (`tax_inclusive`, read by the tax calculator). At most one active
 * default per company and currency. Audited as `core.price_list.*`.
 */
class PriceList extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['company_id', 'name', 'currency', 'tax_inclusive', 'is_default'];

    protected $attributes = [
        'tax_inclusive' => false,
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
