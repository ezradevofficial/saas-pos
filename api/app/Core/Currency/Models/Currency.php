<?php

namespace App\Core\Currency\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A currency of the global ISO 4217 catalogue (CUR-01). Read-only for the
 * runtime role; `currencies:sync` writes it as the schema owner (ADR 002).
 */
class Currency extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'numeric_code' => 'integer',
            'default_decimals' => 'integer',
            'active_in_iso' => 'boolean',
        ];
    }
}
