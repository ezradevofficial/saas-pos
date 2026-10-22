<?php

namespace Modules\Ecommerce\Models;

use App\Core\Currency\FxSnapshot;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ECOM-42, ECOM-43: part of a delivered order coming back (`return`), or
 * coming back for another item (`exchange`): what goes back is refunded,
 * an exchange's difference is collected or refunded. Audited (AUD-01),
 * never deleted (TEN-06).
 */
class OrderReturn extends EcomModel
{
    protected $table = 'ecom_returns';

    protected string $auditResource = 'return';

    public const RETURN = 'return';

    public const EXCHANGE = 'exchange';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'refund_detail' => 'array', 'flags' => 'array', 'seq' => 'integer',
            'returned_net_minor' => 'integer', 'returned_tax_minor' => 'integer', 'returned_total_minor' => 'integer',
            'exchange_net_minor' => 'integer', 'exchange_tax_minor' => 'integer', 'exchange_total_minor' => 'integer',
            'refund_minor' => 'integer', 'collect_minor' => 'integer', 'base_returned_total_minor' => 'integer', 'base_exchange_total_minor' => 'integer',
            'at' => 'immutable_datetime', 'refunded_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime', 'exchange_posted_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id')->orderBy('line_no');
    }

    /** @param array<string, mixed> $detail */
    public function flag(string $code, array $detail = []): void
    {
        $this->flags = [...($this->flags ?? []), ['code' => $code, 'detail' => $detail]];
    }
}
