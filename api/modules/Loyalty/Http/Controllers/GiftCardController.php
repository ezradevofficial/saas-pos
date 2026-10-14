<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Http\Requests\GiftCardRequest;
use Modules\Loyalty\Http\Requests\ListLoyaltyRequest;
use Modules\Loyalty\Http\Requests\RecordRequest;
use Modules\Loyalty\Http\Resources\GiftCardResource;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Support\LoyaltyAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** LOY-20..LOY-23: gift cards and vouchers in the back office. */
class GiftCardController
{
    public function __construct(private readonly GiftCards $cards) {}

    public function index(ListLoyaltyRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(GiftCard::query());
        $search = strtoupper(trim((string) $request->validated('search', '')));

        if ($search !== '') {
            // By the last four characters (the only part of a code kept), or a whole code.
            $query->where(fn ($q) => $q->where('last4', 'like', '%'.addcslashes(substr($search, -4), '\\%_').'%')->orWhere('code_hash', GiftCards::hash($search)));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return GiftCardResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    /** LOY-20: issue; each card's code is in this answer only. */
    public function store(GiftCardRequest $request): JsonResponse
    {
        $issued = $this->cards->issue([
            'company_id' => $request->validated('company_id'),
            'kind' => $request->validated('kind'),
            'currency' => $request->validated('currency'),
            'amount_minor' => $request->validated('amount_minor'),
            'expires_on' => $request->validated('expires_on'),
            'active' => (bool) $request->validated('active', false),
            'party_id' => $request->validated('party_id'),
            'reference' => $request->validated('reference'),
        ], (int) $request->validated('count', 1), $request->user());

        return response()->json([
            'data' => array_map(fn (array $row) => GiftCardResource::make($row['card']->refresh())->withCode($row['code'])->resolve($request), $issued),
        ], 201);
    }

    public function show(RecordRequest $request, GiftCard $loyaltyGiftCard): GiftCardResource
    {
        return GiftCardResource::make($loyaltyGiftCard->load(['entries', 'party']));
    }

    public function sell(GiftCardRequest $request, GiftCard $loyaltyGiftCard): GiftCardResource
    {
        return GiftCardResource::make($this->cards->sell($loyaltyGiftCard, $request->validated('amount_minor'), $request->validated('reference'), $request->user())->load('entries'));
    }

    public function block(RecordRequest $request, GiftCard $loyaltyGiftCard): GiftCardResource
    {
        return GiftCardResource::make($this->cards->block($loyaltyGiftCard, true, $request->user()));
    }

    public function unblock(RecordRequest $request, GiftCard $loyaltyGiftCard): GiftCardResource
    {
        return GiftCardResource::make($this->cards->block($loyaltyGiftCard, false, $request->user()));
    }

    /** LOY-23: a card's balance by its code, for a card of a company the user sees (else not found). */
    public function lookup(GiftCardRequest $request, LoyaltyAccess $access): GiftCardResource
    {
        $card = $this->cards->find($request->validated('code'));

        abort_unless($card !== null && $access->allows($request->user(), 'loyalty.gift_card.view', $card->company_id), 404);

        return GiftCardResource::make($card);
    }
}
