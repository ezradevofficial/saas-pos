<?php

namespace Modules\POS\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Http\Controllers\HeldController;

/**
 * GET pos/held: voids, refunds and cash pay-outs waiting for review (H2),
 * for a holder of the matching permission (`pos.sale.void`,
 * `pos.sale.refund`, `pos.cash.move`) at their locations. `?kind=` and
 * `?flag=` narrow it.
 */
class ListHeldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        return collect(HeldController::PERMISSIONS)->contains(fn (string $permission) => $resolver->can($this->user(), $permission));
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', 'in:'.implode(',', array_keys(HeldController::PERMISSIONS))],
            'flag' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
        ];
    }
}
