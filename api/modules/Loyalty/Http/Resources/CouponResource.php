<?php

namespace Modules\Loyalty\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Loyalty\Models\Coupon;

/** LOY-09: a coupon code with its limit and the uses counted. */
class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Coupon $coupon */
        $coupon = $this->resource;

        return [
            'id' => $coupon->id,
            'promotion_id' => $coupon->promotion_id,
            'code' => $coupon->code,
            'max_uses' => $coupon->max_uses,
            'uses' => (int) $coupon->uses,
            'remaining' => $coupon->remaining(),
            'archived_at' => $coupon->archived_at?->toIso8601String(),
        ];
    }
}
