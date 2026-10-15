<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Payroll\Support\Access;
use Modules\Payroll\Support\PayFields;

/**
 * The base of Payroll's requests (PAY-27, RBAC-04): permissions are checked
 * at the employee's place or at the row's company; a record out of the
 * reader's places is not found (404), never confirmed.
 */
abstract class PayrollRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    protected function payFields(): PayFields
    {
        return app(PayFields::class);
    }

    /** 404 unless the user holds $view or $permission at $record's place; then whether they hold $permission there. */
    protected function onRecord(HasScope $record, string $permission, string $view): bool
    {
        abort_unless($this->access()->can($this->user(), $view, $record) || $this->access()->can($this->user(), $permission, $record), 404);

        return $this->access()->can($this->user(), $permission, $record);
    }

    /** 404 unless the user reads $companyId's rows with $view (or holds $permission there); then whether they hold $permission at the company. */
    protected function onCompany(string $companyId, string $permission, string $view): bool
    {
        abort_unless($this->access()->readsCompany($this->user(), $view, $companyId) || $this->access()->at($this->user(), $permission, Scope::company($companyId)), 404);

        return $this->access()->at($this->user(), $permission, Scope::company($companyId));
    }

    protected function forbid(): never
    {
        throw new ApiException(403, 'forbidden', __('payroll.errors.forbidden'));
    }
}
