<?php

namespace App\Core\MasterData\Sharing;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TEN-08: the sharing mode of one master data type in a tenant. A type
 * without a row is shared. Changed only through MasterDataSharing::switch,
 * which audits the change as `core.master_data_settings.update`.
 */
class MasterDataSetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['data_type', 'mode', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
