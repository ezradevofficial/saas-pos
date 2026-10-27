<?php

namespace Modules\Hotel\Contracts;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\HotelServiceProvider;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Support\Properties;
use Modules\Hotel\Support\Text;

/**
 * HOT-22, HOT-23: the one way another module charges a guest's room.
 *
 * POS reaches it through the room_charge tender (the core tender seam and
 * the POS events); Restaurant and bar outlets call it directly: `lookup()`
 * to find the in-house guest of a room number, `post()` to charge, and
 * `reverse()` to take a charge back. It answers only while the Hotel module
 * is active for the tenant, validates every time, and posts one charge per
 * `(source, source_id)`, so a repeat posts nothing twice. It never refuses
 * with an exception: the caller (a till that already took the order) gets a
 * `reason` and keeps the charge for the front desk (device wins, NFR-04).
 *
 * Reasons: `module_inactive`, `room_unknown`, `not_in_house`,
 * `folio_settled`, `currency_mismatch`, `limit_exceeded`, `amount_invalid`.
 *
 * Restaurant (feat/restaurant-bar) is built in parallel; nothing of it is
 * touched here. It uses `Modules\Hotel\Contracts\RoomCharges` only.
 */
final class RoomCharges
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Properties $properties,
        private readonly Folios $folios,
    ) {}

    /** Whether the Hotel module is on for the current tenant. */
    public function available(): bool
    {
        return $this->modules->isActive(HotelServiceProvider::MODULE);
    }

    /**
     * The in-house guest of a room at a property, validated for a charge of $amountMinor in $currency (both optional).
     *
     * @return array{allowed: bool, reason: ?string, room_number: ?string, stay_id: ?string, folio_id: ?string, guest_name: ?string, currency: ?string, balance_minor: ?int}
     */
    public function lookup(string $branchId, string $roomNumber, ?string $currency = null, ?int $amountMinor = null): array
    {
        $none = ['allowed' => false, 'room_number' => null, 'stay_id' => null, 'folio_id' => null, 'guest_name' => null, 'currency' => null, 'balance_minor' => null];

        if (! $this->available()) {
            return [...$none, 'reason' => 'module_inactive'];
        }

        $room = Room::query()->where('branch_id', $branchId)->whereNull('archived_at')->whereRaw('lower(number) = ?', [mb_strtolower(trim($roomNumber))])->first();

        if ($room === null) {
            return [...$none, 'reason' => 'room_unknown'];
        }

        $stay = Stay::query()->where('room_id', $room->id)->where('status', Stay::CHECKED_IN)->first();

        if ($stay === null) {
            return [...$none, 'room_number' => $room->number, 'reason' => 'not_in_house'];
        }

        $folio = $this->folios->billingFolio($stay);
        $found = [
            'allowed' => true, 'reason' => null, 'room_number' => $room->number, 'stay_id' => $stay->id, 'folio_id' => $folio->id,
            'guest_name' => Guest::query()->whereKey($stay->guest_id)->value('name'), 'currency' => $folio->currency, 'balance_minor' => $this->folios->balance($folio),
        ];

        return match (true) {
            ! $folio->isOpen() => [...$found, 'allowed' => false, 'reason' => 'folio_settled'],
            $currency !== null && $currency !== $folio->currency => [...$found, 'allowed' => false, 'reason' => 'currency_mismatch'],
            $amountMinor !== null && $amountMinor === 0 => [...$found, 'allowed' => false, 'reason' => 'amount_invalid'],
            $amountMinor !== null && $this->overLimit($folio, $found['balance_minor'], $amountMinor) => [...$found, 'allowed' => false, 'reason' => 'limit_exceeded'],
            default => $found,
        };
    }

    /**
     * Charge a room. `$charge`: branch_id, room_number, amount_minor (> 0; a negative amount is a credit, a refund), currency, source
     * (`pos.sale`, `restaurant.order`...), source_id (unique per source), reference? (the receipt or order number), description?.
     *
     * @param  array{branch_id: string, room_number: string, amount_minor: int, currency: string, source: string, source_id: string, reference?: ?string, description?: ?string}  $charge
     * @return array{posted: bool, duplicate: bool, reason: ?string, folio_id: ?string, line_id: ?string}
     */
    public function post(array $charge): array
    {
        $amount = (int) $charge['amount_minor'];
        $found = $this->lookup($charge['branch_id'], $charge['room_number'], $charge['currency'], $amount > 0 ? $amount : null);

        // A repeat of a charge already on the folio is answered with it, whatever the guest's state now.
        $existing = $found['folio_id'] === null ? null : FolioLine::query()->where('folio_id', $found['folio_id'])->where('source', $charge['source'])->where('source_id', $charge['source_id'])->first();

        if ($existing !== null) {
            return ['posted' => false, 'duplicate' => true, 'reason' => null, 'folio_id' => $existing->folio_id, 'line_id' => $existing->id];
        }

        if (! $found['allowed'] && ! ($amount < 0 && $found['reason'] === 'limit_exceeded')) {
            return ['posted' => false, 'duplicate' => false, 'reason' => $found['reason'], 'folio_id' => $found['folio_id'], 'line_id' => null];
        }

        if ($amount === 0) {
            return ['posted' => false, 'duplicate' => false, 'reason' => 'amount_invalid', 'folio_id' => $found['folio_id'], 'line_id' => null];
        }

        $folio = Folio::query()->findOrFail($found['folio_id']);
        $line = $this->folios->post($folio, [
            'kind' => FolioLine::POS_CHARGE,
            'description' => $charge['description'] ?? Text::line($amount > 0 ? 'hotel.lines.room_charge' : 'hotel.lines.room_charge_credit', ['reference' => (string) ($charge['reference'] ?? '')]),
            'amount_minor' => $amount,
            'net_minor' => $amount,
            'source' => $charge['source'],
            'source_id' => $charge['source_id'],
            'reference' => $charge['reference'] ?? null,
        ]);

        return ['posted' => $line->wasRecentlyCreated, 'duplicate' => ! $line->wasRecentlyCreated, 'reason' => null, 'folio_id' => $folio->id, 'line_id' => $line->id];
    }

    /**
     * Take back a charge made with `post()` (a voided sale or order). False when there was nothing to take back, or the folio is
     * settled (the caller keeps it for the front desk).
     */
    public function reverse(string $source, string $sourceId): bool
    {
        if (! $this->available()) {
            return false;
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($source, $sourceId) {
            $line = FolioLine::query()->where('kind', FolioLine::POS_CHARGE)->where('source', $source)->where('source_id', $sourceId)->first();

            if ($line === null || FolioLine::query()->where('reverses_id', $line->id)->exists()) {
                return false;
            }

            $folio = Folio::query()->findOrFail($line->folio_id);

            if (! $folio->isOpen()) {
                return false;
            }

            $this->folios->post($folio, [...$this->folios->reversal($line, FolioLine::VOID, 'reversal', null, null), 'description' => Text::line('hotel.lines.void', ['description' => $line->description])]);

            return true;
        });
    }

    private function overLimit(Folio $folio, int $balance, int $amount): bool
    {
        $limit = Settings::for($folio->company_id)->charge_limit_minor;

        return $limit !== null && $balance + $amount > $limit;
    }
}
