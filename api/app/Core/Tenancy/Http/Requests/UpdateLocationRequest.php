<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Location $location */
        $location = $this->route('location');

        abort_unless($this->user()->can('view', $location), 404);

        return $this->user()->can('update', $location);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'in:'.implode(',', StoreLocationRequest::TYPES)],
        ];
    }
}
