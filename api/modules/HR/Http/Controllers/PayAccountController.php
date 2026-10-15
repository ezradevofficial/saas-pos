<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Employees\PayAccounts;
use Modules\HR\Http\Requests\ListPayAccountsRequest;
use Modules\HR\Http\Requests\PayAccountRequest;
use Modules\HR\Http\Requests\StorePayAccountRequest;
use Modules\HR\Http\Resources\PayAccountResource;
use Modules\HR\Models\Employee;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\Sensitive;

/** HR-04: an employee's pay accounts, in full for holders of `hr.bank.view` (reads audited, HR-11). */
class PayAccountController
{
    public function __construct(
        private readonly PayAccounts $accounts,
        private readonly Sensitive $sensitive,
    ) {}

    public function index(ListPayAccountsRequest $request, Employee $hrEmployee): AnonymousResourceCollection
    {
        $status = $request->validated('status', 'active');
        $accounts = PayAccount::query()->where('employee_id', $hrEmployee->id)
            ->when($status === 'active', fn ($q) => $q->whereNull('archived_at'))
            ->when($status === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->orderByDesc('is_primary')->orderBy('created_at')->orderBy('id')->get();

        if ($accounts->isNotEmpty()) {
            $this->sensitive->recordView($hrEmployee, [Sensitive::BANK], 'pay_accounts');
        }

        return PayAccountResource::collection($accounts);
    }

    public function store(StorePayAccountRequest $request, Employee $hrEmployee): JsonResponse
    {
        $this->sensitive->recordView($hrEmployee, [Sensitive::BANK], 'pay_accounts');

        return PayAccountResource::make($this->accounts->create($hrEmployee, $request->validated()))->response()->setStatusCode(201);
    }

    public function update(PayAccountRequest $request, PayAccount $hrPayAccount): PayAccountResource
    {
        return PayAccountResource::make($this->accounts->update($hrPayAccount, $request->validated()));
    }

    public function archive(PayAccountRequest $request, PayAccount $hrPayAccount): PayAccountResource
    {
        return PayAccountResource::make($this->accounts->archive($hrPayAccount));
    }
}
