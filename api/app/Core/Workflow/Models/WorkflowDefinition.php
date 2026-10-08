<?php

namespace App\Core\Workflow\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A tenant's flow for a document type in one company, or in every company
 * without its own (company_id null) (WF-02, WF-03). Its versions hold the
 * graphs (APR-09). Changes are audited by FlowDefinitions as
 * `core.workflow.*` (AUD-01).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_type
 * @property ?string $company_id
 */
class WorkflowDefinition extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['document_type', 'company_id', 'created_by'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'definition_id');
    }

    public function published(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class, 'definition_id')->where('status', WorkflowVersion::PUBLISHED);
    }

    public function draft(): HasOne
    {
        return $this->hasOne(WorkflowVersion::class, 'definition_id')->where('status', WorkflowVersion::DRAFT);
    }
}
