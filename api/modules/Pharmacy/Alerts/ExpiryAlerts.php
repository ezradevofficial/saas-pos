<?php

namespace Modules\Pharmacy\Alerts;

use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Models\ExpiryNotice;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotBalance;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-10: the nightly expiry sweep for the current tenant.
 *
 * For each company, in its own day: lots with stock that expired are
 * quarantined when the company asks for it (`quarantine_expired`), and the
 * holders of `pharmacy.lot.view` at each location are told once per lot and
 * stage, in one message per location and stage: lots that entered the
 * near-expiry window (`near_expiry_days`) and lots that expired. A second
 * run the same day sends nothing again.
 */
class ExpiryAlerts
{
    private const LISTED = 5;

    public function __construct(private readonly Lots $lots, private readonly PharmacyNotices $notices, private readonly TenantContext $tenants) {}

    /** @return array{near: int, expired: int, quarantined: int} what this run found */
    public function run(): array
    {
        $found = ['near' => 0, 'expired' => 0, 'quarantined' => 0];

        foreach (Company::query()->get() as $company) {
            $settings = Settings::for($company->id);
            $today = CompanyClock::today($company->id);
            $limit = CarbonImmutable::parse($today)->addDays($settings->near_expiry_days)->toDateString();

            $balances = LotBalance::query()->with(['lot.item'])->where('company_id', $company->id)->where('qty', '>', 0)
                ->whereHas('lot', fn ($q) => $q->where('expires_on', '<=', $limit))->get();

            foreach ($balances->pluck('lot')->unique('id') as $lot) {
                if ($lot->expires_on->toDateString() < $today && $settings->quarantine_expired && $lot->status === Lot::ACTIVE) {
                    $this->lots->quarantine($lot, 'expired', null, true);
                    $found['quarantined']++;
                }
            }

            foreach ($balances->groupBy('location_id') as $locationId => $rows) {
                foreach ([ExpiryNotice::EXPIRED => fn (LotBalance $b) => $b->lot->expires_on->toDateString() < $today, ExpiryNotice::NEAR => fn (LotBalance $b) => $b->lot->expires_on->toDateString() >= $today] as $stage => $matches) {
                    $fresh = $rows->filter($matches)->filter(fn (LotBalance $b) => $this->mark($b->lot_id, $locationId, $stage));

                    if ($fresh->isEmpty()) {
                        continue;
                    }

                    $found[$stage === ExpiryNotice::EXPIRED ? 'expired' : 'near'] += $fresh->count();
                    $names = $fresh->map(fn (LotBalance $b) => ($b->lot->item?->name ?? '').' '.$b->lot->batch_number.' ('.Qty::show($b->qty).')')->unique()->values();
                    $location = (string) Location::query()->whereKey($locationId)->value('name');
                    $listed = $names->take(self::LISTED)->implode(', ').($names->count() > self::LISTED ? ', ...' : '');

                    $this->notices->send(
                        $stage === ExpiryNotice::EXPIRED ? PharmacyNotices::EXPIRED : PharmacyNotices::NEAR_EXPIRY,
                        'pharmacy.lot.view',
                        $locationId,
                        ['location_name' => $location, 'count' => (string) $fresh->count(), 'items' => $listed, 'days' => (string) $settings->near_expiry_days],
                        '/pharmacy/lots?status='.($stage === ExpiryNotice::EXPIRED ? 'expired' : 'near_expiry').'&location='.rawurlencode($locationId),
                    );
                }
            }
        }

        return $found;
    }

    /** Whether this lot and place had no alert at this stage yet (it has one now). */
    private function mark(string $lotId, string $locationId, string $stage): bool
    {
        try {
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => ExpiryNotice::create(['lot_id' => $lotId, 'location_id' => $locationId, 'stage' => $stage, 'notified_at' => CarbonImmutable::now()]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
