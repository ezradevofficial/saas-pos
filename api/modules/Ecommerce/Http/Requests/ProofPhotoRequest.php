<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Policies\DeliveryPolicy;

/**
 * POST ecommerce/rider/deliveries/{delivery}/photo (ECOM-28, ECOM-41): a
 * photo as proof of delivery, by the delivery's own rider
 * (DeliveryPolicy::deliver; another's delivery is not found): a JPEG, PNG
 * or WebP whose content really is an image (its dimensions are read), at
 * most `ecommerce.rider.max_photo_kb` and `max_photo_pixels` a side.
 */
class ProofPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(DeliveryPolicy::class)->deliver($this->user(), $this->delivery()), 404);

        return true;
    }

    public function rules(): array
    {
        $side = (int) config('ecommerce.rider.max_photo_pixels', 6000);

        return ['photo' => [
            'required', 'file', 'max:'.(int) config('ecommerce.rider.max_photo_kb', 4096),
            'mimetypes:image/jpeg,image/png,image/webp',
            "dimensions:min_width=1,min_height=1,max_width={$side},max_height={$side}",
        ]];
    }

    public function attributes(): array
    {
        return ['photo' => __('ecommerce.attributes.photo')];
    }

    public function messages(): array
    {
        return [
            'photo.max' => __('ecommerce.errors.photo_too_large', ['max' => (int) round((int) config('ecommerce.rider.max_photo_kb', 4096) / 1024).' MB']),
            'photo.mimetypes' => __('ecommerce.errors.photo_type'),
            'photo.dimensions' => __('ecommerce.errors.photo_invalid'),
        ];
    }

    public function delivery(): Delivery
    {
        return $this->route('ecom_delivery');
    }
}
