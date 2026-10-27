<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Pharmacy\Support\Access;

/**
 * What Pharmacy's write requests share: the user must hold the request's
 * permission somewhere (403 otherwise); once the body is valid, the places it
 * names (`places()`: field => permission) are checked, so a user acts only
 * where they hold the permission (RBAC-04, 403). Ids are checked with
 * `exists` under row-level security: another tenant's id does not exist (422).
 */
abstract class PharmacyRequest extends FormRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->permission());
    }

    /** @return array<string, string> request field => permission needed at that location */
    protected function places(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->places() as $field => $permission) {
                app(Access::class)->require($this->user(), $permission, (string) $this->input($field));
            }
        }];
    }
}
