<?php

namespace Modules\CRM\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What every CRM table shares (docs/modules/crm.md): a tenant row under
 * row-level security (TEN-01, CRM-35) with a UUID v7 key, audited as
 * `crm.{resource}.*` on every create, change, archive and restore
 * (AUD-01). Never deleted (TEN-06).
 */
abstract class CrmModel extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected string $auditModule = 'crm';
}
