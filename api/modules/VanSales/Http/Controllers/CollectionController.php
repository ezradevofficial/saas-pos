<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\POS\Sync\Authority;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Requests\VoidCollectionRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\Collection;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Reconciliation\Reopening;
use Modules\VanSales\Support\BaseAmounts;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Places;

/** VAN-17: collections of customers' balances, and their voiding. */
class CollectionController
{
    public function __construct(
        private readonly Places $places,
        private readonly Views $views,
        private readonly Ledger $ledger,
        private readonly Authority $authority,
        private readonly BaseAmounts $base,
        private readonly CurrencyDecimals $decimals,
        private readonly ScopeResolver $resolver,
        private readonly Reopening $reopening,
    ) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Collection::query()->orderByDesc('collected_at')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        foreach (['company_id', 'branch_id', 'customer_id', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $request->filled('from') && $query->where('collected_at', '>=', CarbonImmutable::parse($request->input('from'))->startOfDay());
        $request->filled('to') && $query->where('collected_at', '<', CarbonImmutable::parse($request->input('to'))->addDay()->startOfDay());
        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $this->views->collections($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * The balance comes back and the run's expected cash or mobile money goes down; the record stays, voided, with who and why.
     * Money taken is given back by someone else than the collector (VAN-17), within their `max_refund_amount` (RBAC-06, in the
     * company's base currency; with no rate stored only an Owner is unlimited). After the day was counted only a person who
     * may decide the reconciliation can void, and the count is reopened for approval.
     */
    public function void(VoidCollectionRequest $request, Collection $vsCollection): JsonResponse
    {
        Errors::transaction(function () use ($request, $vsCollection) {
            $collection = Collection::query()->whereKey($vsCollection->id)->lockForUpdate()->firstOrFail();
            $user = $request->user();

            if ($collection->status !== 'recorded') {
                throw Errors::make(409, 'collection_voided');
            }

            if ($collection->user_id === $user->id) {
                throw Errors::make(403, 'void_own_collection');
            }

            $scope = Scope::location($collection->location_id);
            $baseMinor = $this->base->minor(Company::query()->findOrFail($collection->company_id), $collection->currency, (int) $collection->amount_minor, CarbonImmutable::now());
            $company = Company::query()->findOrFail($collection->company_id);
            $major = BigDecimal::ofUnscaledValue((string) ($baseMinor ?? '999999999999999'), $this->decimals->for((string) $company->base_currency));

            if (! $this->authority->within($user, 'max_refund_amount', $scope, $major)) {
                throw Errors::make(403, 'void_limit_exceeded');
            }

            $run = Run::query()->whereKey($collection->run_id)->lockForUpdate()->firstOrFail();

            if ($run->status !== 'open' && ! $this->resolver->can($user, 'van_sales.reconciliation.approve', $scope)) {
                throw Errors::make(403, 'collection_counted');
            }

            $at = CarbonImmutable::now();
            $collection->forceFill([
                'status' => 'voided', 'void_reason' => $request->validated('reason'), 'voided_by' => $request->user()->id, 'voided_at' => $at,
            ])->save();
            $this->ledger->add($collection->company_id, $collection->customer_id, $collection->run_id, 'collection_void', $collection->id, $collection->currency, (int) $collection->amount_minor, $at);
            Tender::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $collection->tenant_id, 'run_id' => $collection->run_id, 'source_type' => 'collection_void',
                'source_id' => $collection->id, 'kind' => $collection->method_type, 'currency' => $collection->currency, 'amount_minor' => -(int) $collection->amount_minor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->reopening->late($run, 'late_void_collection');
            $vsCollection->refresh();
        });

        return response()->json(['data' => $this->views->collections(collect([$vsCollection->refresh()]))[0]]);
    }
}
