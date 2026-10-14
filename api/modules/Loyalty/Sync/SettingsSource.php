<?php

namespace Modules\Loyalty\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Programme;

/**
 * LOY-01, NFR-04: a `module` row (always: the module is on, so gift cards,
 * vouchers and store credit are tenders at the till) and the till's
 * company programme (`programme`, when there is one): what the till needs
 * to show points, take them as a tender and sell gift cards offline.
 */
class SettingsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'loyalty_settings';
    }

    public function module(): string
    {
        return LoyaltyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        // Always present while the module is active: gift cards, vouchers and
        // store credit need no points programme (only the points tender does).
        $rows = [['id' => 'module', 'active' => true]];
        $programme = Programme::query()->where('company_id', $scope->companyId())->first();

        if ($programme === null) {
            return $rows;
        }

        $rows[] = [
            'id' => 'programme',
            'active' => (bool) $programme->active,
            'currency' => $programme->currency,
            'earn_points' => (int) $programme->earn_points,
            'earn_spend_minor' => (string) $programme->earn_spend_minor,
            'point_value_minor' => (string) $programme->point_value_minor,
            'min_redeem_points' => (string) $programme->min_redeem_points,
            'expiry_months' => $programme->expiry_months,
            'credit_warning_percent' => (int) $programme->credit_warning_percent,
            'gift_card_item_id' => $programme->gift_card_item_id,
        ];

        return $rows;
    }
}
