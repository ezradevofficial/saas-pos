<?php

namespace Modules\Hotel\Pos;

use App\Core\Payments\Tenders\TenderProvider;
use App\Core\Payments\Tenders\TenderUse;
use Modules\Hotel\Contracts\RoomCharges;
use Modules\Hotel\HotelServiceProvider;

/**
 * HOT-22, LOY-27: the `room_charge` payment method type (MD-04), provided
 * through the core tender seam like Loyalty's `credit`. The till sends
 * `tender: {room}` (the room number the cashier typed or picked, with the
 * guest's folio id when it knew it); when a sale or refund is uploaded the
 * server asks RoomCharges whether that room holds an in-house guest whose
 * open folio takes this amount in this currency. What it notices is flagged
 * (`tender_flagged`), never refused: the money has changed hands (device
 * wins, NFR-04); HandlePosEvents then keeps an unpostable charge for the
 * front desk.
 */
final class RoomChargeTender implements TenderProvider
{
    public function __construct(private readonly RoomCharges $rooms) {}

    public function type(): string
    {
        return 'room_charge';
    }

    public function module(): string
    {
        return HotelServiceProvider::MODULE;
    }

    public function check(TenderUse $use): array
    {
        $room = trim((string) ($use->tender['room'] ?? ''));

        if ($room === '') {
            return ['room_charge_no_room'];
        }

        // A refund is a credit to the folio: only the room must exist.
        $found = $this->rooms->lookup($use->branchId, $room, $use->kind === TenderUse::SALE ? $use->currency : null, $use->kind === TenderUse::SALE ? (int) $use->amountMinor : null);

        return $found['allowed'] || ($use->kind === TenderUse::REFUND && $found['reason'] === 'not_in_house')
            ? []
            : ['room_charge_'.$found['reason']];
    }
}
