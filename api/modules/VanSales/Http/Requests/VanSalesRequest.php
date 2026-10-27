<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\VanSales\Support\Places;

/**
 * The base of Van sales requests (RBAC-01 to RBAC-04). A route names the
 * permission it needs with `->defaults('permission', 'van_sales.x.y')`.
 * `authorize()` checks that the person holds it somewhere (403); the place
 * the request touches (a location in the body, or the bound record) is
 * checked at that place, after the body is valid, so ids outside the
 * person's scope are refused (403) and never confirmed.
 */
abstract class VanSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->permission());
    }

    public function rules(): array
    {
        return [];
    }

    public function permission(): string
    {
        return (string) $this->route('permission');
    }

    /** The scope the request acts at once validated; null for a request checked by authorize() alone. */
    protected function target(): ?Scope
    {
        return null;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            $target = $validator->errors()->isEmpty() ? $this->target() : null;

            if ($target !== null && ! app(ScopeResolver::class)->can($this->user(), $this->permission(), $target)) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }
        }];
    }

    protected function atLocation(?string $id): ?Scope
    {
        return $id === null || $id === '' ? null : Scope::location($id);
    }

    protected function places(): Places
    {
        return app(Places::class);
    }
}
