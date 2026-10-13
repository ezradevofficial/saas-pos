<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Accounting\Fx\Revaluer;
use Modules\Accounting\Http\Requests\ClosePeriodRequest;
use Modules\Accounting\Http\Requests\ListPeriodsRequest;
use Modules\Accounting\Http\Requests\ReopenPeriodRequest;
use Modules\Accounting\Http\Requests\RevaluePeriodRequest;
use Modules\Accounting\Http\Resources\RecordResource;
use Modules\Accounting\Ledger\PeriodClose;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\Revaluation;

/** ACC-08, ACC-15: periods of a fiscal year, close (with revaluation) and reopen. */
class PeriodController
{
    public function __construct(private readonly PeriodClose $close) {}

    public function index(ListPeriodsRequest $request, Company $company, Periods $periods): AnonymousResourceCollection
    {
        $year = (int) $request->validated('year', $periods->fiscalYearOf($company, CarbonImmutable::now($company->timezone ?: 'UTC')));
        $rows = $periods->year($company, $year);
        $revalued = Revaluation::query()->whereIn('period_id', $rows->pluck('id'))->pluck('period_id')->all();
        $rows->each(fn (Period $period) => $period->setAttribute('revalued', in_array($period->id, $revalued, true)));

        return RecordResource::collection($rows)->additional(['meta' => ['fiscal_year' => $year]]);
    }

    public function close(ClosePeriodRequest $request, Period $accPeriod): RecordResource
    {
        return RecordResource::make($this->close->close($accPeriod, $request->user(), (bool) $request->validated('revalue', false)));
    }

    public function reopen(ReopenPeriodRequest $request, Period $accPeriod): RecordResource
    {
        return RecordResource::make($this->close->reopen($accPeriod, $request->user(), $request->validated('reason')));
    }

    public function revalue(RevaluePeriodRequest $request, Period $accPeriod, Revaluer $revaluer): JsonResponse
    {
        $revaluation = $revaluer->revalue($accPeriod, $request->user());

        return response()->json(['data' => [
            'id' => $revaluation->id, 'as_of' => $revaluation->as_of->toDateString(), 'rates' => $revaluation->rates, 'items' => $revaluation->items,
            'journal_id' => $revaluation->journal_id, 'reversal_journal_id' => $revaluation->reversal_journal_id,
        ]], 201);
    }
}
