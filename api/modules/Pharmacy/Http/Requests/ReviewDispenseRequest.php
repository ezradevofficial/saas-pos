<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST pharmacy/dispenses/{id}/review (PHA-26): `pharmacy.dispense.review` at
 * the dispense's location (anything else is not found); `{note}`.
 */
class ReviewDispenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(ScopeResolver::class)->can($this->user(), 'pharmacy.dispense.review', Scope::location($this->route('pharmacy_dispense')->location_id)), 404);

        return true;
    }

    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }
}
