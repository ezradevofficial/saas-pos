<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\Archivable;

/** ECOM-01: an online store of a company, sold from one branch and fulfilled from its location. */
class Store extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_stores';

    protected string $auditResource = 'store';

    protected $hidden = ['whatsapp_token_hash'];

    protected function casts(): array
    {
        return ['pickup_location_ids' => 'array', 'payment_methods' => 'array', 'delivery_enabled' => 'boolean', 'pickup_enabled' => 'boolean', 'min_order_minor' => 'integer', 'reservation_minutes' => 'integer'];
    }

    public const DRAFT = 'draft';

    public const LIVE = 'live';

    public const PAUSED = 'paused';

    public const STATUSES = [self::DRAFT, self::LIVE, self::PAUSED];

    /** ECOM-15: the payment kinds a store may offer, with the core payment method type each needs (MD-04). */
    public const PAYMENT_KINDS = ['mpesa' => 'mobile_money', 'card' => 'card', 'cod' => 'cash', 'points' => 'points', 'gift_card' => 'voucher'];

    /** The core payment method a kind is paid with here, or null when the store does not offer it. */
    public function methodFor(string $kind): ?string
    {
        foreach ($this->payment_methods ?? [] as $entry) {
            if (($entry['kind'] ?? null) === $kind) {
                return $entry['payment_method_id'] ?? null;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_values(array_unique(array_column($this->payment_methods ?? [], 'kind')));
    }
}
