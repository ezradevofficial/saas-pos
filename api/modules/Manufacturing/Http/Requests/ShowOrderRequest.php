<?php

namespace Modules\Manufacturing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Manufacturing\Models\Order;
use Modules\Manufacturing\Support\Places;

/**
 * GET manufacturing/orders/{id} (MFG-21): one order for a holder of
 * `manufacturing.order.view` at its location (RBAC-04); anything else is
 * not found, so ids out of scope are never confirmed. Also the base of the
 * order's actions, each needing its own permission at the location (403).
 */
class ShowOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->sees(), 404);

        return $this->permission() === null || $this->mayAt($this->permission());
    }

    public function rules(): array
    {
        return [];
    }

    public function order(): Order
    {
        return $this->route('mfg_order');
    }

    /** The permission the action needs at the order's location (none: reading). */
    protected function permission(): ?string
    {
        return null;
    }

    protected function sees(): bool
    {
        return app(Places::class)->at($this->user(), 'manufacturing.order.view', $this->order()->location_id);
    }

    protected function mayAt(string $permission): bool
    {
        return app(Places::class)->at($this->user(), $permission, $this->order()->location_id);
    }
}
