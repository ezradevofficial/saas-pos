<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Policies\ReturnPolicy;

/**
 * POST ecommerce/returns/{return}/refund (`ecommerce.order.refund`) and
 * …/collect (`ecommerce.order.manage`), ECOM-42, ECOM-43: a return the user
 * does not see is not found; seen without the permission at its location,
 * refused (ReturnPolicy). Collecting names a payment method, checked to be
 * an active one of the order's company (Returns::collect).
 */
class ReturnActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = app(ReturnPolicy::class);
        $return = $this->orderReturn();
        abort_unless($policy->viewReturn($this->user(), $return), 404);

        return $this->collecting() ? $policy->collect($this->user(), $return) : $policy->refund($this->user(), $return);
    }

    public function rules(): array
    {
        return [
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment_method_id' => [$this->collecting() ? 'required' : 'prohibited', 'uuid', Rule::exists('payment_methods', 'id')],
        ];
    }

    public function orderReturn(): OrderReturn
    {
        return $this->route('ecom_return');
    }

    private function collecting(): bool
    {
        return str_ends_with((string) $this->route()?->uri(), '/collect');
    }
}
