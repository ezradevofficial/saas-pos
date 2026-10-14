<?php

namespace Modules\HR\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Access;
use Modules\HR\Support\Sensitive;

/**
 * The base of HR's requests (HR-38, RBAC-04): permissions are checked at
 * the employee's place (location, else branch, else company) or at the
 * setup row's company; a record out of the reader's places is not found
 * (404), never confirmed.
 */
abstract class HrRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    protected function sensitive(): Sensitive
    {
        return app(Sensitive::class);
    }

    /** The route's employee (`hr_employee`). */
    public function employee(): Employee
    {
        return $this->route('hr_employee');
    }

    /** 404 unless the user holds $view at $employee's place; then whether they hold $permission there. */
    protected function onEmployee(Employee $employee, string $permission, string $view = 'hr.employee.view'): bool
    {
        abort_unless($this->access()->can($this->user(), $view, $employee) || $this->access()->can($this->user(), $permission, $employee), 404);

        return $this->access()->can($this->user(), $permission, $employee);
    }

    /** 404 unless the user reads the company's setup with $view; then whether they hold $permission at the company. */
    protected function onCompany(string $companyId, string $permission, string $view): bool
    {
        abort_unless($this->access()->readsCompany($this->user(), $view, $companyId) || $this->access()->at($this->user(), $permission, Scope::company($companyId)), 404);

        return $this->access()->at($this->user(), $permission, Scope::company($companyId));
    }

    /** 403 with HR's wording. */
    protected function forbid(): never
    {
        throw new ApiException(403, 'forbidden', __('hr.errors.place_forbidden'));
    }
}
