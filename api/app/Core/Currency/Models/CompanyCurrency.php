<?php

namespace App\Core\Currency\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One of a company's reporting currencies, at position 1..3 (CUR-02).
 * Replaced as a set by PUT companies/{company}/currencies, which audits the
 * change on the company (`core.company.currencies_update`).
 */
class CompanyCurrency extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const MAX_REPORTING = 3;

    protected $table = 'company_reporting_currencies';

    protected $fillable = ['company_id', 'code', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
