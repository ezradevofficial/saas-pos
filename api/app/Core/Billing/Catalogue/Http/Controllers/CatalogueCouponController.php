<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Http\CataloguePresenter;
use App\Core\Billing\Catalogue\Http\Requests\ManageCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\StoreCatalogueCouponRequest;
use App\Core\Billing\Catalogue\Http\Requests\UpdateCatalogueCouponRequest;
use App\Core\Billing\Catalogue\Http\Requests\ViewCatalogueRequest;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

/**
 * SUB-01: coupons. Their value (kind, percent, amount, currency, what they
 * apply to) is fixed once created; the expiry and the redemption limit may
 * change. Archived, never deleted.
 */
class CatalogueCouponController extends Controller
{
    public function __construct(private readonly PlatformAuditor $audit) {}

    public function index(ViewCatalogueRequest $request): JsonResponse
    {
        $coupons = CatalogueCoupon::query()->orderByRaw('archived_at is not null')->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['data' => $coupons->map(fn (CatalogueCoupon $c) => CataloguePresenter::coupon($c))->all()]);
    }

    public function store(StoreCatalogueCouponRequest $request): JsonResponse
    {
        $coupon = PlatformDatabase::transaction(function () use ($request) {
            $data = $request->validated();
            $coupon = CatalogueCoupon::create([
                ...Arr::only($data, ['kind', 'percent', 'amount', 'expires_at', 'max_redemptions']),
                'code' => strtoupper($data['code']),
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
                'applies_to_modules' => array_values($data['applies_to_modules'] ?? []),
                'applies_to_bundles' => array_values($data['applies_to_bundles'] ?? []),
                'created_by' => $request->staff()?->id,
            ]);
            $this->audit->record('catalogue.coupon.created', $coupon, null, CataloguePresenter::coupon($coupon->refresh()));

            return $coupon;
        });

        return response()->json(['data' => CataloguePresenter::coupon($coupon)], 201);
    }

    public function update(UpdateCatalogueCouponRequest $request, CatalogueCoupon $catalogueCoupon): JsonResponse
    {
        PlatformDatabase::transaction(function () use ($request, $catalogueCoupon) {
            $before = CataloguePresenter::coupon($catalogueCoupon);
            $catalogueCoupon->fill(Arr::only($request->validated(), ['expires_at', 'max_redemptions']))->save();
            $this->audit->record('catalogue.coupon.updated', $catalogueCoupon, $before, CataloguePresenter::coupon($catalogueCoupon));
        });

        return response()->json(['data' => CataloguePresenter::coupon($catalogueCoupon)]);
    }

    public function archive(ManageCatalogueRequest $request, CatalogueCoupon $catalogueCoupon): JsonResponse
    {
        if ($catalogueCoupon->archived_at === null) {
            PlatformDatabase::transaction(function () use ($catalogueCoupon) {
                $catalogueCoupon->forceFill(['archived_at' => now()])->save();
                $this->audit->record('catalogue.coupon.archived', $catalogueCoupon);
            });
        }

        return response()->json(['data' => CataloguePresenter::coupon($catalogueCoupon)]);
    }
}
