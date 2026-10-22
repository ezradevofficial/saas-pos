<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET ecommerce/proof-photos/{path} (ECOM-41): a proof photo behind a
 * temporary signed URL (`signed` middleware). The signature is the
 * credential; ProofPhotoController enters the file's tenant and checks the
 * signed-for user may still see the delivery (DeliveryPolicy::view).
 */
class ProofPhotoFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
