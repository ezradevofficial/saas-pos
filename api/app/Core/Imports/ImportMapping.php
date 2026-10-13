<?php

namespace App\Core\Imports;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * IMP-02: the column mapping last used for an importer in the tenant,
 * as column key => header text, offered first on the next upload.
 */
class ImportMapping extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['importer', 'mapping'];

    protected function casts(): array
    {
        return ['mapping' => JsonObject::class];
    }
}
