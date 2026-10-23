<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Policies\ReturnPolicy;

/**
 * POST ecommerce/orders/{order}/returns and …/returns/quote (ECOM-42,
 * ECOM-43): lines of the order and quantities coming back, a reason, and
 * optionally products of the order's store given instead. An order the
 * user does not see is not found; seen without `ecommerce.order.manage`
 * there, it is refused (ReturnPolicy). Lines and products are looked up in
 * the order and its store under the tenant's RLS (Returns), never trusted.
 */
class ReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = app(ReturnPolicy::class);
        abort_unless($policy->view($this->user(), $this->order()), 404);

        return $policy->create($this->user(), $this->order());
    }

    public function rules(): array
    {
        $quote = str_ends_with((string) $this->route()?->uri(), '/quote');

        return [
            'reason' => [$quote ? 'sometimes' : 'required', 'nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => ['required', 'string', 'max:12'],
            'exchange' => ['sometimes', 'array', 'max:50'],
            'exchange.*.product_id' => ['required', 'uuid'],
            'exchange.*.qty' => ['required', 'string', 'max:12'],
        ];
    }

    public function order(): Order
    {
        return $this->route('ecom_order');
    }
}
