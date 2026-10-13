<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\Promotion;

/** LOY-08: a promotion with its definition (the engine's fields) and, on its page, its coupons. */
class PromotionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->resource;

        return [
            'id' => $promotion->id,
            'company_id' => $promotion->company_id,
            'company' => $promotion->relationLoaded('company') ? ['id' => $promotion->company_id, 'name' => $promotion->company?->name] : null,
            'name' => $promotion->name,
            'description' => $promotion->description,
            'type' => $promotion->type,
            'status' => $promotion->status,
            'priority' => $promotion->priority,
            'version' => $promotion->version,
            'requires_coupon' => (bool) $promotion->requires_coupon,
            ...$promotion->definition,
            'coupons' => $this->whenLoaded('coupons', fn () => $promotion->coupons->map(fn (Coupon $c) => CouponResource::make($c)->resolve($request))->values()->all()),
            'created_at' => $promotion->created_at?->toIso8601String(),
            'updated_at' => $promotion->updated_at?->toIso8601String(),
            'archived_at' => $promotion->archived_at?->toIso8601String(),
        ];
    }
}
