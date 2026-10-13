<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ACC-16: a budget of a company's fiscal year, in base currency. */
class Budget extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, CompanyRecord, HasUuids;

    protected $table = 'acc_budgets';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'budget';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
