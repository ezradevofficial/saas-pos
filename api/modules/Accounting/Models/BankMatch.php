<?php

namespace Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-14: a statement line matched to a ledger line; undone by setting unmatched_at, never deleted. */
class BankMatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_bank_matches';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'unmatched_at' => 'immutable_datetime'];
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
