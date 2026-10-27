<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\VanSales\Http\Requests\ReviewCreditRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\CreditEntry;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Places;

/** VAN-11 to VAN-13: customers' route balances and the review queue of flagged credit sales. */
class CreditController
{
    public function __construct(private readonly Places $places, private readonly Views $views, private readonly Auditor $auditor) {}

    public function accounts(ViewRequest $request): JsonResponse
    {
        $entries = CreditEntry::query()->select('customer_id')->distinct();
        $this->places->filterCompanies($entries, $request->user(), $request->permission());
        $query = Party::query()->whereIn('id', $entries)->orderBy('name')->orderBy('id');

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.addcslashes((string) $request->input('search'), '\\%_').'%');
        }

        return response()->json(['data' => $this->views->accounts($query->limit(500)->get())]);
    }

    public function account(ViewRequest $request, string $customerId): JsonResponse
    {
        $party = Str::isUuid($customerId) ? Party::query()->find($customerId) : null;

        if ($party === null || ! CreditEntry::query()->where('customer_id', $party->id)->exists()) {
            throw Errors::make(404, 'customer_unknown');
        }

        if ($party->company_id !== null && ! $this->places->atCompany($request->user(), $request->permission(), $party->company_id)) {
            throw Errors::make(403, 'not_permitted');
        }

        return response()->json(['data' => $this->views->accounts(collect([$party]), true)[0]]);
    }

    public function reviews(ViewRequest $request): JsonResponse
    {
        $runs = Run::query()->select('id');
        $this->places->filterLocations($runs, $request->user(), $request->permission());
        $status = (string) $request->input('review_status', 'pending');
        $query = VanSale::query()->whereIn('run_id', $runs)->whereNotNull('review_status')->orderByDesc('sold_at')->orderBy('id');

        if ($status !== 'all') {
            $query->where('review_status', $status);
        }

        foreach (['company_id', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $this->views->creditReviews($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** VAN-12: reviewing changes no money; it is audited with who and what they wrote. */
    public function review(ReviewCreditRequest $request, VanSale $vsSale): JsonResponse
    {
        Errors::transaction(function () use ($request, $vsSale) {
            $sale = VanSale::query()->whereKey($vsSale->id)->lockForUpdate()->firstOrFail();

            if ($sale->review_status !== 'pending') {
                throw Errors::make(409, 'review_not_pending');
            }

            $sale->forceFill([
                'review_status' => 'reviewed', 'reviewed_by' => $request->user()->id, 'reviewed_at' => CarbonImmutable::now(),
                'review_note' => $request->validated('note'),
            ])->save();
            $this->auditor->record('van_sales.sale.review', $sale, ['review_status' => 'pending'], ['review_status' => 'reviewed', 'flags' => $sale->flags, 'note' => $request->validated('note')]);
        });

        return response()->json(['data' => $this->views->creditReviews(collect([$vsSale->refresh()]))[0]]);
    }
}
