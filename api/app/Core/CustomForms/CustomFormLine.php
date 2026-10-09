<?php

namespace App\Core\CustomForms;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CF-05: one line of a custom form record, its values custom fields of
 * `custom_form_line:<key>`. Lines are written with their record (replaced
 * as a whole while it is a draft) and audited in the record's entry.
 *
 * @property string $record_id
 * @property int $position
 * @property array $custom
 */
class CustomFormLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['record_id', 'position'];

    protected $attributes = ['custom' => '{}'];

    protected function casts(): array
    {
        return ['custom' => 'array', 'position' => 'integer'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CustomFormRecord::class, 'record_id');
    }
}
