<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Loyalty\Http\Requests\CouponRequest;
use Modules\Loyalty\Http\Requests\RecordRequest;
use Modules\Loyalty\Http\Resources\CouponResource;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Support\LoyaltyError;

/** LOY-09: a promotion's coupon codes (audited through the model). */
class CouponController
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function index(RecordRequest $request, Promotion $loyaltyPromotion): AnonymousResourceCollection
    {
        return CouponResource::collection($loyaltyPromotion->coupons()->get());
    }

    public function store(CouponRequest $request, Promotion $loyaltyPromotion): JsonResponse
    {
        $coupons = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request, $loyaltyPromotion) {
            $codes = $request->codes();

            for ($i = 0, $n = (int) $request->validated('generate', 0); $i < $n; $i++) {
                $codes[] = $this->generate($loyaltyPromotion->company_id, $codes);
            }

            return array_map(fn (string $code) => Coupon::create([
                'promotion_id' => $loyaltyPromotion->id,
                'company_id' => $loyaltyPromotion->company_id,
                'code' => $code,
                'max_uses' => $request->validated('max_uses'),
                'created_by' => $request->user()->id,
            ]), $codes);
        });

        return CouponResource::collection(collect($coupons))->response()->setStatusCode(201);
    }

    public function archive(RecordRequest $request, Coupon $loyaltyCoupon): CouponResource
    {
        if ($loyaltyCoupon->isArchived()) {
            throw LoyaltyError::make('coupon_archived');
        }

        $loyaltyCoupon->archive();

        return CouponResource::make($loyaltyCoupon->refresh());
    }

    /** An eight-character code not used in the company (nor in this batch). @param list<string> $taken */
    private function generate(string $companyId, array $taken): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (in_array($code, $taken, true) || Coupon::query()->where('company_id', $companyId)->where('code', $code)->exists());

        return Str::upper($code);
    }
}
