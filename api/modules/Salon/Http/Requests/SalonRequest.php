<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Salon\Support\Places;

/**
 * The base of Salon requests (RBAC-01 to RBAC-04). A route names the permission it needs with
 * `->defaults('permission', 'salon.x.y')`. `authorize()` checks that the person holds it somewhere (403); the place the
 * request touches (a branch or company in the body, or the bound record) is checked at that place after the body is
 * valid, so ids outside the person's scope are refused (403) and never confirmed.
 */
abstract class SalonRequest extends FormRequest
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

    protected function atBranch(mixed $id): ?Scope
    {
        return is_string($id) && $id !== '' ? Scope::branch($id) : null;
    }

    protected function atCompany(mixed $id): ?Scope
    {
        return is_string($id) && $id !== '' ? Scope::company($id) : null;
    }

    /**
     * Company-wide records (clients, the menu) are read and kept by people at any branch: the permission anywhere in the
     * company is what the request needs (403 otherwise). Records of one branch are checked at that branch instead.
     */
    protected function reachesCompany(mixed $id): ?Scope
    {
        if (is_string($id) && $id !== '' && ! $this->places()->inCompany($this->user(), $this->permission(), $id)) {
            throw new AuthorizationException(__('rbac.forbidden'));
        }

        return null;
    }

    protected function places(): Places
    {
        return app(Places::class);
    }
}
