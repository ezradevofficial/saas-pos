<?php

namespace Modules\CRM\Models;

use App\Core\Tenancy\Archivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-02: an open stage of a pipeline with its default probability.
 *
 * @property string $pipeline_id
 * @property string $name
 * @property int $position
 * @property int $probability
 */
class Stage extends CrmModel
{
    use Archivable;

    protected $table = 'crm_stages';

    protected function casts(): array
    {
        return ['position' => 'integer', 'probability' => 'integer'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }
}
