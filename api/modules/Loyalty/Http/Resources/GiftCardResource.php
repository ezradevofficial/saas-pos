<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\GiftCard;

/** LOY-20..LOY-23: a gift card or voucher, by its last four characters (the code is never shown again). */
class GiftCardResource extends JsonResource
{
    /** The code, answered once when the card is issued. */
    public ?string $code = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var GiftCard $card */
        $card = $this->resource;

        return [
            'id' => $card->id,
            'company_id' => $card->company_id,
            'kind' => $card->kind,
            'last4' => $card->last4,
            'code' => $this->when($this->code !== null, fn () => $this->code),
            'currency' => $card->currency,
            'initial' => ['amount_minor' => (string) $card->initial_minor, 'currency' => $card->currency],
            'balance' => ['amount_minor' => (string) $card->balance_minor, 'currency' => $card->currency],
            'status' => $card->status,
            'expired' => $card->isExpired(),
            'expires_on' => $card->expires_on?->format('Y-m-d'),
            'party' => $card->party_id === null ? null : ['id' => $card->party_id, 'name' => $card->relationLoaded('party') ? $card->party?->name : null],
            'sold_reference' => $card->sold_reference,
            'issued_at' => $card->issued_at?->toIso8601String(),
            'activated_at' => $card->activated_at?->toIso8601String(),
            'entries' => $this->whenLoaded('entries', fn () => EntryResource::collection($card->entries)->resolve($request)),
        ];
    }

    public function withCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }
}
