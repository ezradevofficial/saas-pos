<?php

namespace Modules\Loyalty\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Loyalty\Http\Lists\PromotionList;
use Modules\Loyalty\Http\Requests\ListLoyaltyRequest;
use Modules\Loyalty\Http\Requests\PreviewPromotionRequest;
use Modules\Loyalty\Http\Requests\PromotionRequest;
use Modules\Loyalty\Http\Requests\RecordRequest;
use Modules\Loyalty\Http\Resources\PromotionResource;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Promotions\PromotionEngine;
use Modules\Loyalty\Promotions\Promotions;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** LOY-08, LOY-13: promotions in the back office. */
class PromotionController
{
    public function __construct(private readonly Promotions $promotions) {}

    public function index(ListLoyaltyRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applyFilters(Promotion::query()->with(PromotionList::RELATIONS));
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PromotionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(PromotionRequest $request): JsonResponse
    {
        $promotion = $this->promotions->create($request->validated(), $request->user());

        return PromotionResource::make($promotion->load(['company', 'coupons']))->response()->setStatusCode(201);
    }

    public function show(RecordRequest $request, Promotion $loyaltyPromotion): PromotionResource
    {
        return PromotionResource::make($loyaltyPromotion->load(['company', 'coupons']));
    }

    public function update(PromotionRequest $request, Promotion $loyaltyPromotion): PromotionResource
    {
        return PromotionResource::make($this->promotions->update($loyaltyPromotion, $request->validated(), $request->user())->load(['company', 'coupons']));
    }

    public function activate(RecordRequest $request, Promotion $loyaltyPromotion): PromotionResource
    {
        return PromotionResource::make($this->promotions->setStatus($loyaltyPromotion, Promotion::ACTIVE, $request->user())->load(['company', 'coupons']));
    }

    public function archive(RecordRequest $request, Promotion $loyaltyPromotion): PromotionResource
    {
        return PromotionResource::make($this->promotions->setStatus($loyaltyPromotion, Promotion::ARCHIVED, $request->user())->load(['company', 'coupons']));
    }

    /** LOY-13: a sample cart evaluated as the till would (nothing is saved). */
    public function preview(PreviewPromotionRequest $request): JsonResponse
    {
        $company = $request->validated('company_id');
        $at = $request->filled('at') ? CarbonImmutable::parse($request->validated('at')) : CarbonImmutable::now();
        $promotions = [];

        if ($request->filled('promotion_ids')) {
            $saved = Promotion::query()->whereIn('id', $request->validated('promotion_ids'))->with('coupons')->get();

            foreach ($saved as $promotion) {
                $promotions[] = Promotions::engineShape($promotion->id, $promotion->version, [
                    ...$promotion->definition, 'type' => $promotion->type, 'priority' => $promotion->priority, 'requires_coupon' => $promotion->requires_coupon,
                ], $promotion->coupons->filter(fn (Coupon $c) => $c->archived_at === null));
            }
        }

        if ($request->filled('promotion')) {
            $draft = (array) $request->validated('promotion');
            $codes = collect($request->validated('coupons', []))->map(fn ($c) => new Coupon(['code' => PromotionEngine::normaliseCode((string) $c)]));
            $promotions[] = Promotions::engineShape('draft', 1, $draft, ($draft['requires_coupon'] ?? false) ? $codes : null);
        }

        $lines = array_map(fn (array $line, int $i) => ['id' => $line['id'] ?? 'line-'.($i + 1), ...$line], $request->validated('lines'), array_keys($request->validated('lines')));
        $party = $request->filled('party_id') ? Party::query()->find($request->validated('party_id')) : null;
        $branch = $request->validated('branch_id');

        $result = PromotionEngine::evaluate(Promotions::engineLines($lines), [
            'currency' => $request->validated('currency'),
            'branch_id' => $branch,
            'customer_tags' => array_values((array) ($party?->tags ?? [])),
            'coupons' => $request->validated('coupons', []),
            'local' => $branch === null
                ? ['date' => $at->format('Y-m-d'), 'weekday' => (int) $at->format('N'), 'time' => $at->format('H:i')]
                : Promotions::localTime($branch, $at),
        ], $promotions);

        return response()->json(['data' => [...$result, 'company_id' => $company]]);
    }
}
