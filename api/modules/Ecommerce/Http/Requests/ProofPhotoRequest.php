<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST ecommerce/rider/deliveries/{delivery}/photo (ECOM-28): a photo as proof of delivery. */
class ProofPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions and scope are checked by the controller at the record's place (RBAC-04).
        return true;
    }

    public function rules(): array
    {
        return ['photo' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:'.(int) config('ecommerce.rider.max_photo_kb', 4096)]];
    }
}
