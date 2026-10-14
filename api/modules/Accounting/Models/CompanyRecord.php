<?php

namespace Modules\Accounting\Models;

use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-22: an accounting record belongs to one company, where its permissions apply (RBAC-04). */
trait CompanyRecord
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
