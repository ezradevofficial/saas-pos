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
        $dispense = $this->route('pharmacy_dispense');
        abort_unless(app(ScopeResolver::class)->can($this->user(), 'pharmacy.dispense.review', Scope::location($dispense->location_id)), 404);
        // Four eyes: nobody closes a flag on a sale they made or approved.
        abort_if(in_array($this->user()->id, [$dispense->cashier_id, $dispense->pharmacist_id], true), 403);

        return true;
    }

    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:1000']];
    }
}
