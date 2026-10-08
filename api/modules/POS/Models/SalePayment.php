<?php

namespace Modules\POS\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** POS-03, CUR-04, CUR-06: one tender of a sale, with the rate the till used. */
class SalePayment extends Model
{
    use BelongsToTenant, HasUuids;

    public const CONFIRMED = 'confirmed';

    public const PENDING = 'pending';

    public const STATUSES = [self::CONFIRMED, self::PENDING];

    protected $table = 'pos_sale_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fx' => FxSnapshot::class.':fx'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
