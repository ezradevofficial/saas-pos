<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Employees\Contracts;
use Modules\HR\Http\Requests\ContractRequest;
use Modules\HR\Http\Requests\ListContractsRequest;
use Modules\HR\Http\Requests\StoreContractRequest;
use Modules\HR\Http\Resources\ContractResource;
use Modules\HR\Models\Contract;
use Modules\HR\Models\Employee;

/** HR-07: an employee's contracts, newest first. */
class ContractController
{
    public function __construct(private readonly Contracts $contracts) {}

    public function index(ListContractsRequest $request, Employee $hrEmployee): AnonymousResourceCollection
    {
        $contracts = Contract::query()->where('employee_id', $hrEmployee->id)->with(['position', 'department'])->orderByDesc('start_date')->orderByDesc('id')->get();
        $contracts->each->setRelation('employee', $hrEmployee);

        return ContractResource::collection($contracts);
    }

    public function store(StoreContractRequest $request, Employee $hrEmployee): JsonResponse
    {
        return ContractResource::make($this->contracts->create($hrEmployee, $request->validated(), $request->user())->load(['position', 'department']))->response()->setStatusCode(201);
    }

    public function update(ContractRequest $request, Contract $hrContract): ContractResource
    {
        return ContractResource::make($this->contracts->update($hrContract, $request->validated())->load(['position', 'department']));
    }

    public function end(ContractRequest $request, Contract $hrContract): ContractResource
    {
        return ContractResource::make($this->contracts->end($hrContract, (string) $request->validated('end_date'), $request->validated('reason'))->load(['position', 'department']));
    }
}
