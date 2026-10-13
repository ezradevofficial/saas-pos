<?php

namespace Modules\Loyalty\Http\Controllers\Device;

use App\Core\Tenancy\Models\Location;
use Illuminate\Http\JsonResponse;
use Modules\Loyalty\Http\Requests\DeviceGiftCardRequest;
use Modules\Loyalty\Models\GiftCard;

/**
 * LOY-23: a till online asks for a card's live balance (by code hash)
 * before accepting it; a card of another company is not found.
 */
class GiftCardLookupController
{
    public function __invoke(DeviceGiftCardRequest $request): JsonResponse
    {
        // The device's company (its location's branch's), from core tenancy, never the body.
        $companyId = Location::query()->with('branch')->findOrFail($request->user()->location_id)->branch->company_id;
        $card = GiftCard::query()->where('code_hash', $request->validated('code_hash'))->where('company_id', $companyId)->first();

        abort_if($card === null, 404);

        return response()->json(['data' => [
            'id' => $card->id,
            'kind' => $card->kind,
            'last4' => $card->last4,
            'currency' => $card->currency,
            'balance_minor' => (string) $card->balance_minor,
            'status' => $card->status,
            'expires_on' => $card->expires_on?->format('Y-m-d'),
            'expired' => $card->isExpired(),
        ]]);
    }
}
