<?php

namespace Modules\Accounting\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ACC-10: a receipt from a customer or a payment to a supplier, posted when recorded. */
class Payment extends Model implements HasScope
{
    use Audited, BelongsToTenant, CompanyRecord, HasUuids;

    public const KINDS = ['receipt', 'payment'];

    protected $table = 'acc_payments';

    protected string $auditModule = 'accounting';

    protected string $auditResource = 'payment';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'fx' => FxSnapshot::class.':fx',
            'amount_minor' => 'integer',
            'base_amount_minor' => 'integer',
            'unallocated_minor' => 'integer',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }
}
