<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Policies\DeliveryPolicy;

/**
 * GET ecommerce/deliveries/{delivery}/track (ECOM-40): a delivery the user
 * does not see is not found; seen without `ecommerce.delivery.track`
 * there, it is refused (DeliveryPolicy).
 */
class TrackDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = app(DeliveryPolicy::class);
        $delivery = $this->delivery();

        abort_unless($policy->view($this->user(), $delivery) || $policy->track($this->user(), $delivery), 404);

        return $policy->track($this->user(), $delivery);
    }

    public function rules(): array
    {
        return [];
    }

    public function delivery(): Delivery
    {
        return $this->route('ecom_delivery');
    }
}
