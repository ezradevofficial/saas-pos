<?php

namespace App\Core\Numbering;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A format's counter for one period (NUM-01): `all` when the format never
 * resets, else the year. Only Numbering moves it, under the row lock.
 */
class NumberSequence extends Model
{
    use BelongsToTenant, HasUuids;

    public const ALL = 'all';

    protected $fillable = ['number_format_id', 'period', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(NumberFormat::class, 'number_format_id');
    }
}
