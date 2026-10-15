<?php

namespace Modules\CRM\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM-02: a sales pipeline of a company, its open stages in order.
 *
 * @property string $company_id
 * @property string $name
 * @property bool $is_default
 */
class Pipeline extends CrmModel
{
    use Archivable;

    protected $table = 'crm_pipelines';

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('position')->orderBy('created_at');
    }
}
