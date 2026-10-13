<?php

namespace App\Core\Imports;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * IMP-02: a record an import created or changed, with its values before
 * the change (null when created), so the batch can be undone.
 */
class ImportRow extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['import_id', 'row_number', 'record_type', 'record_id', 'action', 'before'];

    protected function casts(): array
    {
        return ['before' => 'array', 'row_number' => 'integer'];
    }
}
