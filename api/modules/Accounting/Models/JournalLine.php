<?php

namespace Modules\Accounting\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Dimensions\CostCentre;
use App\Core\MasterData\Dimensions\Department;
use App\Core\MasterData\Dimensions\Project;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-03, ACC-21: one side of a journal, in its transaction currency and in base currency (CUR-04). */
class JournalLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_journal_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
            'base_debit_minor' => 'integer',
            'base_credit_minor' => 'integer',
            'line_no' => 'integer',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
