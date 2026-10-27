<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/** POST pharmacy/write-offs/{id}/cancel (PHA-12): not found unless seen, then `pharmacy.writeoff.cancel` at its location (403). Body `{reason}`. */
class CancelWriteOffRequest extends ShowWriteOffRequest
{
    public function authorize(): bool
    {
        parent::authorize();

        return app(ScopeResolver::class)->can($this->user(), 'pharmacy.writeoff.cancel', Scope::location($this->route('pharmacy_write_off')->location_id));
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
