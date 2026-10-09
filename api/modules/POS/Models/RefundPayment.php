<?php

namespace Modules\POS\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** POS-05: money returned for a refund, per method and currency. */
class RefundPayment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pos_refund_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fx' => FxSnapshot::class.':fx'];
    }
}
