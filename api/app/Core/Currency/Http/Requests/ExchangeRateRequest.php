<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CUR-03: a company's exchange rates are read by anyone holding
 * `core.exchange_rate.view` at the company or beneath it (a cashier at one
 * of its outlets). A company the user cannot see is not found (RBAC-04);
 * one they see without the permission is forbidden.
 */
class ExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');
        $visibility = app(Visibility::class);
        $reaches = $visibility->reaches($this->user(), 'core.exchange_rate.view', $company);

        abort_unless($reaches || $visibility->reaches($this->user(), 'core.company.view', $company), 404);

        return $this->allowed($company, $reaches);
    }

    protected function allowed(Company $company, bool $reachesForView): bool
    {
        return $reachesForView;
    }

    public function rules(): array
    {
        return [];
    }
}
