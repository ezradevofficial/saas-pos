<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Pharmacy\Models\Prescription;

/**
 * GET pharmacy/prescriptions/{id}: `pharmacy.prescription.view` where it was
 * made; anything else is not found. Also the base of its action requests
 * (cancel, image upload, image link), which then need their own permission
 * at that location (403).
 */
class ShowPrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->can('pharmacy.prescription.view'), 404);

        $needed = $this->needs();

        return $needed === null || $this->can($needed);
    }

    public function rules(): array
    {
        return [];
    }

    /** The permission the action needs beyond viewing, or null when it only reads. */
    protected function needs(): ?string
    {
        return null;
    }

    public function prescription(): Prescription
    {
        return $this->route('pharmacy_prescription');
    }

    protected function can(string $permission): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $permission, Scope::location($this->prescription()->location_id));
    }
}
