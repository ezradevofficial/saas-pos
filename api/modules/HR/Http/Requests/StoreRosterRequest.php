<?php

namespace Modules\HR\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Validator;

/** POST hr/rosters (HR-17): `hr.roster.manage` at the roster's branch (else company). */
class StoreRosterRequest extends SetupStoreRequest
{
    protected function permission(): string
    {
        return 'hr.roster.manage';
    }

    protected function fields(): array
    {
        return [...RosterRequest::FIELDS(true), ...collect(RosterRequest::MEMBERS())->map(fn ($rules, $key) => $key === 'members' ? ['sometimes', 'array', 'max:500'] : $rules)->all()];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $scope = $this->filled('branch_id') ? Scope::branch((string) $this->input('branch_id')) : Scope::company((string) $this->input('company_id'));

            if (! $this->access()->at($this->user(), 'hr.roster.manage', $scope)) {
                $this->forbid();
            }
        }];
    }
}
