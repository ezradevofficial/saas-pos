<?php

namespace Modules\FixedAssets\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * FA-22: a source document raised to Accounting (its id is the source id,
 * so Accounting posts it once), kept for the Accounting backfill (ACC-29).
 */
class Posting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fa_postings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_on' => 'immutable_date', 'amounts' => 'array', 'reverse' => 'boolean'];
    }
}
