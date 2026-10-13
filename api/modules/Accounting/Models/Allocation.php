<?php

namespace Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-10: part of a receipt, payment or credit note settling a document, with its realised FX difference. */
class Allocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_allocations';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount_minor' => 'integer',
            'base_at_document_minor' => 'integer',
            'base_at_source_minor' => 'integer',
            'fx_difference_minor' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creditDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'credit_document_id');
    }
}
