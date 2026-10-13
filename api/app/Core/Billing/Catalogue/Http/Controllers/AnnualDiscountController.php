<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Http\CataloguePresenter;
use App\Core\Billing\Catalogue\Http\Requests\ManageCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\StoreAnnualDiscountRequest;
use App\Core\Billing\Catalogue\Http\Requests\ViewCatalogueRequest;
use App\Core\Billing\Catalogue\Models\AnnualDiscount;
use App\Core\Http\ApiException;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** SUB-01: the annual discount ("2 months free"), versioned like prices. */
class AnnualDiscountController extends Controller
{
    public function __construct(private readonly PlatformAuditor $audit) {}

    public function index(ViewCatalogueRequest $request): JsonResponse
    {
        $rows = AnnualDiscount::query()->orderByDesc('effective_from')->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn (AnnualDiscount $d) => CataloguePresenter::annualDiscount($d))->all()]);
    }

    public function store(StoreAnnualDiscountRequest $request): JsonResponse
    {
        $data = $request->validated();

        $row = PlatformDatabase::transaction(function () use ($data, $request) {
            if (AnnualDiscount::live()->whereDate('effective_from', $data['effective_from'])->exists()) {
                throw new ApiException(409, 'price_exists', __('billing.price.exists'), ['effective_from' => [__('billing.price.exists')]]);
            }

            $row = AnnualDiscount::create([
                'months_charged' => (int) $data['months_charged'],
                'months_in_cycle' => (int) ($data['months_in_cycle'] ?? 12),
                'effective_from' => $data['effective_from'],
                'created_by' => $request->staff()?->id,
            ]);
            $this->audit->record('catalogue.annual_discount.created', $row, null, CataloguePresenter::annualDiscount($row));

            return $row;
        });

        return response()->json(['data' => CataloguePresenter::annualDiscount($row->refresh())], 201);
    }

    public function archive(ManageCatalogueRequest $request, AnnualDiscount $annualDiscount): JsonResponse
    {
        if ($annualDiscount->archived_at === null) {
            if ($annualDiscount->isInEffect()) {
                throw new ApiException(409, 'price_in_effect', __('billing.price.in_effect'));
            }

            PlatformDatabase::transaction(function () use ($annualDiscount) {
                $annualDiscount->forceFill(['archived_at' => now()])->save();
                $this->audit->record('catalogue.annual_discount.archived', $annualDiscount);
            });
        }

        return response()->json(['data' => CataloguePresenter::annualDiscount($annualDiscount)]);
    }
}
