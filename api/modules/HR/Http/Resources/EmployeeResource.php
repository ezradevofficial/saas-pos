<?php

namespace Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HR\Models\Employee;
use Modules\HR\Models\EmployeeIdentifier;
use Modules\HR\Models\PayAccount;
use Modules\HR\Support\HrPack;
use Modules\HR\Support\Sensitive;

/**
 * HR-02, HR-09, HR-11: an employee as the reader may see it. Personal
 * fields follow the reader's field rules; the sensitive groups show only
 * to holders of their view permission at the employee's place (and not
 * hidden by field rules): date of birth and pay in lists; identifiers, pay
 * accounts and medical notes in the detail, masked (identifiers, accounts)
 * or left out otherwise. Showing a group in full is audited (HR-11).
 * `self()` renders the employee's own view (HR-31): identifiers and
 * accounts masked, never in full.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    private bool $detail = false;

    private bool $self = false;

    public function detail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function self(): static
    {
        $this->detail = true;
        $this->self = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Employee $employee */
        $employee = $this->resource;
        $user = $request->user();
        $sensitive = app(Sensitive::class);
        $hidden = $user === null ? [] : $sensitive->hiddenFields($user, $request);
        $sees = fn (string $group) => ! $this->self && $user !== null && $sensitive->sees($user, $employee, $group, $request);
        $shown = [];

        $data = [
            'id' => $employee->id,
            'number' => $employee->number,
            'name' => $employee->displayName(),
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'gender' => $employee->gender,
            'company' => Refs::named($employee->company),
            'branch' => Refs::named($employee->branch),
            'location' => Refs::named($employee->location),
            'department' => Refs::coded($employee->department),
            'position' => $employee->position === null ? null : ['id' => $employee->position->id, 'code' => $employee->position->code, 'title' => $employee->position->title],
            'reports_to' => Refs::employee($employee->manager),
            'user' => Refs::user($employee->user),
            'status' => $employee->status,
            'hire_date' => Refs::date($employee->hire_date),
            'termination_date' => Refs::date($employee->termination_date),
            'termination_reason' => $employee->termination_reason,
            'work_email' => $employee->work_email,
            'work_phone' => $employee->work_phone,
            'personal_email' => $employee->personal_email,
            'personal_phone' => $employee->personal_phone,
            'address' => $employee->address,
            'emergency_contacts' => $employee->emergency_contacts ?? [],
            'clock_methods' => $employee->clock_methods ?? [],
            'biometric_ref' => $employee->biometric_ref,
            'custom' => (object) ($employee->custom ?? []),
            'created_at' => Refs::at($employee->created_at),
        ];

        if ($this->self || $sees(Sensitive::IDENTITY)) {
            $data['date_of_birth'] = Refs::date($employee->date_of_birth);
            $shown[] = $this->self ? null : Sensitive::IDENTITY;
        }

        if ($sees(Sensitive::PAY)) {
            $contract = $employee->currentContract;
            $data['pay'] = $contract === null ? null : [...Refs::money($contract->pay_minor, $contract->currency), 'basis' => $contract->pay_basis];
            $shown[] = Sensitive::PAY;
        }

        if ($this->detail) {
            $data += $this->sensitiveDetail($employee, $sees, $shown);
            $data['has_clock_pin'] = $employee->clockPin()->exists();

            if (! $this->self && $user !== null) {
                $data['access'] = $this->accessOf($employee, $sensitive, $user, $request);
            }
        }

        $shown = array_values(array_filter(array_unique($shown)));

        if ($shown !== []) {
            $sensitive->recordView($employee, $shown, $this->detail ? 'record' : 'list');
        }

        // RBAC-05: fields the reader's rules hide are left out.
        return array_diff_key($data, array_flip($hidden));
    }

    /**
     * @param  list<?string>  $shown
     * @return array<string, mixed>
     */
    private function sensitiveDetail(Employee $employee, callable $sees, array &$shown): array
    {
        $data = [];
        $identity = $sees(Sensitive::IDENTITY);
        $data['identifiers'] = $employee->identifiers->map(fn (EmployeeIdentifier $id) => [
            'type' => $id->type,
            'label' => HrPack::label('identifiers', $id->type),
            'value' => $identity ? $id->value : null,
            'masked' => Sensitive::mask($id->value_hint),
        ])->values()->all();

        $bank = $sees(Sensitive::BANK);
        $data['pay_accounts'] = $employee->payAccounts->map(fn (PayAccount $account) => PayAccountResource::present($account, $bank))->values()->all();

        if ($bank && $employee->payAccounts->isNotEmpty()) {
            $shown[] = Sensitive::BANK;
        }

        if ($identity && $employee->identifiers->isNotEmpty()) {
            $shown[] = Sensitive::IDENTITY;
        }

        if ($sees(Sensitive::MEDICAL)) {
            $data['medical_notes'] = $employee->medical_notes;
            $shown[] = $employee->medical_notes === null ? null : Sensitive::MEDICAL;
        }

        return $data;
    }

    /** @return array<string, array{view: bool, edit: bool}> what the reader may see and change (the UI hides the rest; RBAC-09) */
    private function accessOf(Employee $employee, Sensitive $sensitive, $user, Request $request): array
    {
        $access = [];

        foreach (array_keys(Sensitive::GROUPS) as $group) {
            $access[$group] = ['view' => $sensitive->sees($user, $employee, $group, $request), 'edit' => $sensitive->mayEdit($user, $employee, $group)];
        }

        return $access;
    }
}
