<?php

namespace Modules\Ecommerce\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What the module's business records share (docs/modules/ecommerce.md): a
 * tenant row under row-level security (TEN-01, ECOM-38) with a UUID v7
 * key, audited as `ecommerce.{resource}.*` on every create, change,
 * archive and restore (AUD-01). Never deleted (TEN-06).
 */
abstract class EcomModel extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected string $auditModule = 'ecommerce';
}
