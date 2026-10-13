<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Accounting\Budgets\Budgets;
use Modules\Accounting\Http\Requests\BudgetActionRequest;
use Modules\Accounting\Http\Requests\ListBudgetsRequest;
use Modules\Accounting\Http\Requests\SetBudgetCellsRequest;
use Modules\Accounting\Http\Requests\ShowBudgetRequest;
use Modules\Accounting\Http\Requests\StoreBudgetRequest;
use Modules\Accounting\Http\Resources\RecordResource;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Reports\LedgerReports;

/** ACC-16: budgets and budget against actual (Accounting Pro). */
class BudgetController
{
    public function index(ListBudgetsRequest $request, Company $company): AnonymousResourceCollection
    {
        $status = (string) $request->validated('status', 'active');
        $query = Budget::query()->where('company_id', $company->id)->orderByDesc('fiscal_year')->orderBy('name');

        if ($status !== 'all') {
            $status === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        }

        return RecordResource::collection($query->get());
    }

    public function store(StoreBudgetRequest $request, Company $company): JsonResponse
    {
        $budget = Budget::query()->create([...$request->validated(), 'company_id' => $company->id, 'created_by' => $request->user()->id]);

        return RecordResource::make($budget)->response()->setStatusCode(201);
    }

    public function show(ShowBudgetRequest $request, Budget $accBudget): RecordResource
    {
        return RecordResource::make($accBudget->load('lines'));
    }

    public function setCells(SetBudgetCellsRequest $request, Budget $accBudget, Budgets $budgets): RecordResource
    {
        return RecordResource::make($budgets->setCells($accBudget, $request->validated('cells'))->load('lines'));
    }

    public function archive(BudgetActionRequest $request, Budget $accBudget): RecordResource
    {
        $accBudget->archive();

        return RecordResource::make($accBudget);
    }

    /** Budget against actual over the budget's fiscal year, or `?from=`/`?to=` inside it. */
    public function vsActual(ShowBudgetRequest $request, Budget $accBudget, LedgerReports $reports, Periods $periods): JsonResponse
    {
        $company = $accBudget->company;
        $year = $periods->year($company, $accBudget->fiscal_year);
        $from = (string) $request->validated('from', $year->first()->starts_on->toDateString());
        $to = (string) $request->validated('to', $year->last()->ends_on->toDateString());

        return response()->json(['data' => $reports->budgetVsActual($company, $accBudget, $from, $to)]);
    }
}
