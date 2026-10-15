<?php

namespace Modules\Payroll\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Payroll\Employees\EmployeeCopy;
use Modules\Payroll\Http\Lists\PayEmployeeList;
use Modules\Payroll\Http\Requests\EmployeeItemRequest;
use Modules\Payroll\Http\Requests\ListPayEmployeesRequest;
use Modules\Payroll\Http\Requests\PayEmployeeRequest;
use Modules\Payroll\Http\Requests\SyncEmployeesRequest;
use Modules\Payroll\Http\Resources\EmployeeItemResource;
use Modules\Payroll\Http\Resources\PayEmployeeResource;
use Modules\Payroll\Models\EmployeeItem;
use Modules\Payroll\Models\PayrollEmployee;
use Modules\Payroll\Support\Errors;
use Modules\Payroll\Support\PayFields;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PAY-05, PAY-06: employees' pay setup and their items, at the reader's places. */
class EmployeeController
{
    public function index(ListPayEmployeesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = PayrollEmployee::query()->with(PayEmployeeList::RELATIONS);
        $request->applyFilters($query);
        $request->applySearch($query, ['number' => 'number', 'name' => 'name']);

        if ($request->filled('pay_group')) {
            $query->where('pay_group_id', $request->validated('pay_group'));
        }

        if ($request->boolean('unassigned')) {
            $query->whereNull('pay_group_id');
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PayEmployeeResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function sync(SyncEmployeesRequest $request, EmployeeCopy $copy): JsonResponse
    {
        $count = Errors::transaction(fn () => $copy->syncCompany($request->validated('company_id'), user: $request->user()));

        return response()->json(['data' => ['employees' => $count]]);
    }

    public function show(PayEmployeeRequest $request, PayrollEmployee $payrollEmployee): PayEmployeeResource
    {
        return PayEmployeeResource::make($payrollEmployee->load(PayEmployeeList::RELATIONS))->detail();
    }

    public function update(PayEmployeeRequest $request, PayrollEmployee $payrollEmployee): PayEmployeeResource
    {
        $payrollEmployee->fill($request->validated())->save();

        return PayEmployeeResource::make($payrollEmployee->refresh()->load(PayEmployeeList::RELATIONS))->detail();
    }

    public function items(PayEmployeeRequest $request, PayrollEmployee $payrollEmployee, PayFields $fields): AnonymousResourceCollection
    {
        $fields->recordList('employee_items', ['payroll_employee_id' => $payrollEmployee->id]);

        return EmployeeItemResource::collection(EmployeeItem::query()->with('item')->where('payroll_employee_id', $payrollEmployee->id)
            ->orderByRaw('archived_at is not null')->orderByDesc('created_at')->get());
    }

    public function storeItem(PayEmployeeRequest $request, PayrollEmployee $payrollEmployee): JsonResponse
    {
        $data = $request->validated();
        $entry = EmployeeItem::query()->create([
            'payroll_employee_id' => $payrollEmployee->id,
            'item_id' => $data['item_id'],
            'amount_minor' => (int) $data['amount_minor'],
            'currency' => $data['currency'],
            'recurrence' => $data['recurrence'],
            'from_date' => $data['recurrence'] === EmployeeItem::RECURRING ? $data['from_date'] : null,
            'to_date' => $data['recurrence'] === EmployeeItem::RECURRING ? ($data['to_date'] ?? null) : null,
            'period_date' => $data['recurrence'] === EmployeeItem::ONCE ? $data['period_date'] : null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return EmployeeItemResource::make($entry->load('item'))->response()->setStatusCode(201);
    }

    public function updateItem(EmployeeItemRequest $request, EmployeeItem $payrollEmployeeItem): EmployeeItemResource
    {
        if ($payrollEmployeeItem->consumed_run_id !== null) {
            throw Errors::make(422, 'item_paid');
        }

        $data = $request->validated();

        if (isset($data['amount_minor'])) {
            $data['amount_minor'] = (int) $data['amount_minor'];
        }

        $payrollEmployeeItem->fill(array_intersect_key($data, array_flip($payrollEmployeeItem->recurrence === EmployeeItem::ONCE
            ? ['amount_minor', 'period_date', 'note'] : ['amount_minor', 'from_date', 'to_date', 'note'])))->save();

        return EmployeeItemResource::make($payrollEmployeeItem->refresh()->load('item'));
    }

    public function archiveItem(EmployeeItemRequest $request, EmployeeItem $payrollEmployeeItem): EmployeeItemResource
    {
        if (! $payrollEmployeeItem->isArchived()) {
            $payrollEmployeeItem->archive();
        }

        return EmployeeItemResource::make($payrollEmployeeItem->refresh()->load('item'));
    }
}
