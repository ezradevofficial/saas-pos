<?php

namespace Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ACC-16: one cell of a budget: an account (and cost centre) in one month of the fiscal year. */
class BudgetLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_budget_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'amount_minor' => 'integer'];
    }
}
