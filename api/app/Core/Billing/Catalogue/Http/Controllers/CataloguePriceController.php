<?php

namespace App\Core\Billing\Catalogue\Http\Controllers;

use App\Core\Billing\Catalogue\Http\CataloguePresenter;
use App\Core\Billing\Catalogue\Http\Requests\ManageCatalogueRequest;
use App\Core\Billing\Catalogue\Http\Requests\StoreCataloguePriceRequest;
use App\Core\Billing\Catalogue\Http\Requests\ViewCatalogueRequest;
use App\Core\Billing\Catalogue\Models\CatalogueCountry;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Http\ApiException;
use App\Core\Platform\PlatformAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SUB-01: versioned prices. A change of price is a new row from a date
 * (today or later); rows are never edited, so what a subscription was
 * sold at stays as it was. A row not yet in effect may be archived.
 */
class CataloguePriceController extends Controller
{
    public function __construct(private readonly PlatformAuditor $audit) {}

    /** GET platform/catalogue/prices?item_type=&item_key=&country=: the history, newest first. */
    public function index(ViewCatalogueRequest $request): JsonResponse
    {
        $request->validate([
            'item_type' => ['sometimes', 'in:module,bundle'],
            'item_key' => ['sometimes', 'string', 'max:40'],
            'country' => ['sometimes', 'string', 'size:2'],
        ]);

        $prices = CataloguePrice::query()
            ->when($request->query('item_type'), fn ($q, $v) => $q->where('item_type', $v))
            ->when($request->query('item_key'), fn ($q, $v) => $q->where('item_key', $v))
            ->when($request->query('country'), fn ($q, $v) => $q->where('country', strtoupper($v)))
            ->orderBy('item_type')->orderBy('item_key')->orderBy('country')->orderByDesc('effective_from')->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json(['data' => $prices->map(fn (CataloguePrice $p) => CataloguePresenter::price($p))->all()]);
    }

    public function store(StoreCataloguePriceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $country = strtoupper($data['country']);

        $price = DB::transaction(function () use ($data, $country, $request) {
            $exists = CataloguePrice::live()
                ->where(['item_type' => $data['item_type'], 'item_key' => $data['item_key'], 'country' => $country])
                ->whereDate('effective_from', $data['effective_from'])
                ->exists();

            if ($exists) {
                throw new ApiException(409, 'price_exists', __('billing.price.exists'), ['effective_from' => [__('billing.price.exists')]]);
            }

            $price = CataloguePrice::create([
                'item_type' => $data['item_type'],
                'item_key' => $data['item_key'],
                'country' => $country,
                // The country's billing currency, never chosen by hand (KE: KES, CD: USD).
                'currency' => CatalogueCountry::findOrFail($country)->currency,
                'amount' => (int) $data['amount'],
                'minimum_amount' => isset($data['minimum_amount']) ? (int) $data['minimum_amount'] : null,
                'extra_outlet_amount' => isset($data['extra_outlet_amount']) ? (int) $data['extra_outlet_amount'] : null,
                'effective_from' => $data['effective_from'],
                'created_by' => $request->staff()?->id,
            ]);

            $this->audit->record('catalogue.price.created', $price, null, CataloguePresenter::price($price));

            return $price;
        });

        return response()->json(['data' => CataloguePresenter::price($price->refresh())], 201);
    }

    /** POST platform/catalogue/prices/{price}/archive: only a price not yet in effect. */
    public function archive(ManageCatalogueRequest $request, CataloguePrice $cataloguePrice): JsonResponse
    {
        if ($cataloguePrice->archived_at !== null) {
            return response()->json(['data' => CataloguePresenter::price($cataloguePrice)]);
        }

        if ($cataloguePrice->isInEffect()) {
            throw new ApiException(409, 'price_in_effect', __('billing.price.in_effect'));
        }

        DB::transaction(function () use ($cataloguePrice) {
            $cataloguePrice->forceFill(['archived_at' => now()])->save();
            $this->audit->record('catalogue.price.archived', $cataloguePrice, null, ['effective_from' => $cataloguePrice->effective_from->toDateString()]);
        });

        return response()->json(['data' => CataloguePresenter::price($cataloguePrice)]);
    }
}
