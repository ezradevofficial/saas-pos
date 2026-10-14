<?php

namespace Modules\Accounting\Models;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ACC-09: a line of a document, with the tax rate as applied (never recomputed later). */
class DocumentLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'acc_document_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'line_no' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
