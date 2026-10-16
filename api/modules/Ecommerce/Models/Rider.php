<?php

namespace Modules\Ecommerce\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Archivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ECOM-26: a rider: a user of the tenant at a company. */
class Rider extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_riders';

    protected string $auditResource = 'rider';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
