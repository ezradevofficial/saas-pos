<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Payroll\Http\Requests\ListLoansRequest;
use Modules\Payroll\Http\Requests\LoanRequest;
use Modules\Payroll\Http\Requests\StoreLoanRequest;
use Modules\Payroll\Http\Resources\LoanResource;
use Modules\Payroll\Loans\Loans;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Support\PayFields;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PAY-13: loans and advances of the employees at the reader's places. */
class LoanController
{
    public function __construct(private readonly Loans $loans) {}

    public function index(ListLoansRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Loan::query()->with('employee')->select('payroll_loans.*')
            ->addSelect(['balance_minor' => fn ($q) => $q->selectRaw('coalesce(sum(amount_minor), 0)')->from('payroll_loan_entries')->whereColumn('loan_id', 'payroll_loans.id')]);
        $request->applyFilters($query, 'employee');
        $request->applySearch($query, ['reference' => 'reference']);

        if ($request->filled('employee')) {
            $query->where('payroll_employee_id', $request->validated('employee'));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        app(PayFields::class)->recordList('loans');

        return LoanResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $loan = $this->loans->create($request->employee(), $request->validated(), $request->user());

        return LoanResource::make($loan->load(['employee', 'entries']))->detail()->response()->setStatusCode(201);
    }

    public function show(LoanRequest $request, Loan $payrollLoan, PayFields $fields): LoanResource
    {
        $fields->recordView($payrollLoan, 'loan');

        return LoanResource::make($payrollLoan->load(['employee', 'entries']))->detail();
    }

    public function update(LoanRequest $request, Loan $payrollLoan): LoanResource
    {
        $data = $request->validated();

        if (isset($data['instalment_minor'])) {
            $data['instalment_minor'] = (int) $data['instalment_minor'];
        }

        return LoanResource::make($this->loans->update($payrollLoan, $data)->load(['employee', 'entries']))->detail();
    }

    public function settle(LoanRequest $request, Loan $payrollLoan): LoanResource
    {
        $loan = $this->loans->settle($payrollLoan, $request->validated('method'), $request->validated('date'), $request->validated('note'), $request->user());

        return LoanResource::make($loan->load(['employee', 'entries']))->detail();
    }

    public function cancel(LoanRequest $request, Loan $payrollLoan): LoanResource
    {
        return LoanResource::make($this->loans->cancel($payrollLoan, $request->validated('reason'), $request->user())->load(['employee', 'entries']))->detail();
    }
}
