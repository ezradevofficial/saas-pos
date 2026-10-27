<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Http\Requests\ArchiveRatePlanRequest;
use Modules\Hotel\Http\Requests\ListRatePlansRequest;
use Modules\Hotel\Http\Requests\RateQuoteRequest;
use Modules\Hotel\Http\Requests\ShowRatePlanRequest;
use Modules\Hotel\Http\Requests\StoreRatePlanRequest;
use Modules\Hotel\Http\Requests\UpdateRatePlanRequest;
use Modules\Hotel\Http\Resources\RatePlanResource;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Rates\RateQuotes;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;

/** HOT-06 to HOT-10: rate plans and their seasons, and the rate quote. Plan changes are audited by the model; season changes by name. */
class RatePlanController
{
    public function __construct(private readonly Access $access, private readonly Auditor $auditor, private readonly RateQuotes $quotes) {}

    public function index(ListRatePlansRequest $request): AnonymousResourceCollection
    {
        $query = $this->access->scopeCompanies(RatePlan::query()->with(['roomType:id,code,name', 'seasons']), $request->user(), 'hotel.rate.view')->orderBy('code');
        $status = $request->validated('status', 'active');
        $status === 'all' ? null : $query->{$status === 'archived' ? 'whereNotNull' : 'whereNull'}('archived_at');

        foreach (['company' => 'company_id', 'room_type' => 'room_type_id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->validated($filter));
            }
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like));
        }

        return RatePlanResource::collection($query->get());
    }

    public function store(StoreRatePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = RoomType::query()->findOrFail($data['room_type_id']);

        $plan = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $type) {
            $plan = RatePlan::create([...collect($data)->except('seasons')->all(), 'company_id' => $type->company_id]);
            $this->replaceSeasons($plan, $data['seasons'] ?? []);

            return $plan;
        });

        return RatePlanResource::make($plan->refresh()->load(['roomType:id,code,name', 'seasons']))->response()->setStatusCode(201);
    }

    public function show(ShowRatePlanRequest $request, RatePlan $hotelRatePlan): RatePlanResource
    {
        return RatePlanResource::make($hotelRatePlan->load(['roomType:id,code,name', 'seasons']));
    }

    public function update(UpdateRatePlanRequest $request, RatePlan $hotelRatePlan): RatePlanResource
    {
        $data = $request->validated();

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($hotelRatePlan, $data) {
            $hotelRatePlan->fill(collect($data)->except('seasons')->all())->save();

            if (array_key_exists('seasons', $data)) {
                $this->replaceSeasons($hotelRatePlan, $data['seasons']);
            }
        });

        return RatePlanResource::make($hotelRatePlan->refresh()->load(['roomType:id,code,name', 'seasons']));
    }

    public function archive(ArchiveRatePlanRequest $request, RatePlan $hotelRatePlan): RatePlanResource
    {
        if (Stay::query()->where('rate_plan_id', $hotelRatePlan->id)->whereIn('status', Stay::HOLDING)->exists()) {
            throw HotelError::make(409, 'rate_plan_in_use');
        }

        $hotelRatePlan->archive();

        return RatePlanResource::make($hotelRatePlan->refresh()->load(['roomType:id,code,name', 'seasons']));
    }

    /** HOT-10: the night-by-night quote, with the taxes and levies of the company's tax codes as an estimate. */
    public function quote(RateQuoteRequest $request): JsonResponse
    {
        $plan = RatePlan::query()->with('seasons')->findOrFail($request->validated('rate_plan_id'));
        $quote = $this->quotes->quote(
            $plan, RoomType::query()->findOrFail($plan->room_type_id), Settings::for($plan->company_id),
            CarbonImmutable::parse($request->validated('arrival_on')), CarbonImmutable::parse($request->validated('departure_on')),
            (int) $request->validated('adults', 1), (int) $request->validated('children', 0),
        );

        return response()->json(['data' => [
            'rate_plan_id' => $plan->id, 'currency' => $quote['currency'], 'board' => $quote['board'], 'tax_inclusive' => $quote['tax_inclusive'],
            'nights' => array_map(fn (array $n) => [...$n, 'rate_minor' => (string) $n['rate_minor'], 'extra_minor' => (string) $n['extra_minor'], 'amount_minor' => (string) $n['amount_minor']], $quote['nights']),
            'total_minor' => (string) $quote['total_minor'],
            'estimate' => ['net_minor' => (string) $quote['estimate']['net_minor'], 'tax_minor' => (string) $quote['estimate']['tax_minor'], 'gross_minor' => (string) $quote['estimate']['gross_minor'], 'flags' => $quote['estimate']['flags']],
        ]]);
    }

    /** @param list<array<string, mixed>> $seasons */
    private function replaceSeasons(RatePlan $plan, array $seasons): void
    {
        $before = $plan->seasons()->get(['name', 'starts_on', 'ends_on', 'weekday_rate_minor', 'weekend_rate_minor', 'priority'])->toArray();
        $plan->seasons()->delete();

        foreach ($seasons as $season) {
            $plan->seasons()->create($season);
        }

        $this->auditor->record('hotel.rate_plan.seasons', $plan, ['seasons' => $before], ['seasons' => $seasons]);
    }
}
