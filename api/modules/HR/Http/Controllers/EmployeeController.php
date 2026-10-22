<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Attendance\ClockPins;
use Modules\HR\Employees\Employees;
use Modules\HR\Employees\Identifiers;
use Modules\HR\Http\Lists\EmployeeList;
use Modules\HR\Http\Requests\ClearClockPinRequest;
use Modules\HR\Http\Requests\ListEmployeesRequest;
use Modules\HR\Http\Requests\PutIdentifiersRequest;
use Modules\HR\Http\Requests\RehireEmployeeRequest;
use Modules\HR\Http\Requests\SetClockPinRequest;
use Modules\HR\Http\Requests\ShowEmployeeRequest;
use Modules\HR\Http\Requests\StoreEmployeeRequest;
use Modules\HR\Http\Requests\TerminateEmployeeRequest;
use Modules\HR\Http\Requests\UpdateEmployeeRequest;
use Modules\HR\Http\Resources\EmployeeResource;
use Modules\HR\Models\Employee;
use Modules\HR\Support\HrPack;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HR-02 to HR-05, HR-23: employees at the reader's places (RBAC-04). */
class EmployeeController
{
    public const DETAIL = [...EmployeeList::RELATIONS, 'identifiers', 'payAccounts'];

    public function __construct(private readonly Employees $employees) {}

    public function index(ListEmployeesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Employee::query()->with(EmployeeList::RELATIONS);
        $request->applyFilters($query, employees: true);
        $request->applySearch($query, ['number' => 'number', 'name' => "concat_ws(' ', hr_employees.first_name, hr_employees.middle_name, hr_employees.last_name)", 'work_email' => 'work_email']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return EmployeeResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowEmployeeRequest $request, Employee $hrEmployee): EmployeeResource
    {
        return EmployeeResource::make($hrEmployee->load(self::DETAIL))->detail()->additional(['meta' => ['identifier_types' => $this->identifierTypes($hrEmployee->company_id)]]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->hire($request->validated(), $request->user());

        return EmployeeResource::make($employee->load(self::DETAIL))->detail()->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $hrEmployee): EmployeeResource
    {
        return EmployeeResource::make($this->employees->update($hrEmployee, $request->validated(), $request->user())->load(self::DETAIL))->detail();
    }

    public function terminate(TerminateEmployeeRequest $request, Employee $hrEmployee): EmployeeResource
    {
        return EmployeeResource::make($this->employees->terminate($hrEmployee, $request->validated(), $request->user())->load(self::DETAIL))->detail();
    }

    public function rehire(RehireEmployeeRequest $request, Employee $hrEmployee): EmployeeResource
    {
        return EmployeeResource::make($this->employees->rehire($hrEmployee, (string) $request->validated('hire_date'), $request->user())->load(self::DETAIL))->detail();
    }

    public function identifiers(PutIdentifiersRequest $request, Employee $hrEmployee, Identifiers $identifiers): EmployeeResource
    {
        $warnings = $identifiers->put($hrEmployee, $request->validated('identifiers'));

        // CP-01: values kept outside a format that only warns (KE national ID).
        return EmployeeResource::make($hrEmployee->refresh()->load(self::DETAIL))->detail()->additional(['meta' => ['warnings' => $warnings]]);
    }

    public function setClockPin(SetClockPinRequest $request, Employee $hrEmployee, ClockPins $pins): JsonResponse
    {
        $record = $pins->set($hrEmployee, (string) $request->validated('pin'), $request->user());

        return new JsonResponse(['data' => ['has_clock_pin' => true, 'version' => $record->version]]);
    }

    public function clearClockPin(ClearClockPinRequest $request, Employee $hrEmployee, ClockPins $pins): JsonResponse
    {
        $pins->clear($hrEmployee, $request->user());

        return new JsonResponse(['data' => ['has_clock_pin' => false]]);
    }

    /** @return list<array{key: string, label: string, required: bool}> */
    private function identifierTypes(string $companyId): array
    {
        return array_values(array_map(fn (array $type) => ['key' => $type['key'], 'label' => HrPack::label('identifiers', $type['key']), 'required' => $type['required']], app(Identifiers::class)->typesFor($companyId)));
    }
}
