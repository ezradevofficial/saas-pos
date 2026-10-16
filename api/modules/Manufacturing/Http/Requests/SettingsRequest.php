<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Manufacturing\Support\Places;

/**
 * GET manufacturing/companies/{company}/settings (MFG-29): a holder of
 * `manufacturing.bom.view`, `manufacturing.order.view` or
 * `manufacturing.settings.edit` anywhere in the company; anything else is
 * not found.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $places = app(Places::class);
        $company = $this->companyId();

        abort_unless(collect(['manufacturing.bom.view', 'manufacturing.order.view', 'manufacturing.settings.edit'])
            ->contains(fn (string $permission) => $places->inCompany($this->user(), $permission, $company)), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function companyId(): string
    {
        return (string) $this->route('company')->getKey();
    }
}
