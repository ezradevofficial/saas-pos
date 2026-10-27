<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * What Hotel's requests share: the user must hold the request's permission
 * (403 otherwise) at the place of the record the route names (its branch, or
 * for company records its company), or somewhere when the route names none;
 * once the body is valid, the places it names (`branches()` and `companies()`:
 * field => permission) are checked too, so a user acts only where they hold
 * the permission (RBAC-04). Ids are checked with `exists` under row-level
 * security: another tenant's id does not exist (422), and a record of another
 * tenant in the route is not found (404).
 */
abstract class HotelRequest extends FormRequest
{
    abstract protected function permission(): string;

    /** The branch of the record the route is about, if it is a branch record. */
    protected function branchId(): ?string
    {
        return null;
    }

    /** The company of the record the route is about, if it is a company record. */
    protected function companyId(): ?string
    {
        return null;
    }

    /** Whether the company records this request changes are company-wide: the permission must be held for the whole company, not one branch. */
    protected function wide(): bool
    {
        return false;
    }

    /** @return array<string, string> request field (a branch id) => permission needed there */
    protected function branches(): array
    {
        return [];
    }

    /** @return array<string, string> request field (a company id) => permission needed there */
    protected function companies(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $user = $this->user();
        $access = app(Access::class);

        return match (true) {
            $this->branchId() !== null => $access->can($user, $this->permission(), $this->branchId()),
            $this->companyId() !== null => $this->wide() ? $access->canCompanyWide($user, $this->permission(), $this->companyId()) : $access->canCompany($user, $this->permission(), $this->companyId()),
            default => app(ScopeResolver::class)->can($user, $this->permission()),
        };
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->branches() as $field => $permission) {
                if ($this->filled($field)) {
                    app(Access::class)->require($this->user(), $permission, (string) $this->input($field));
                }
            }

            foreach ($this->companies() as $field => $permission) {
                if ($this->filled($field)) {
                    $this->wide() ? app(Access::class)->requireCompanyWide($this->user(), $permission, (string) $this->input($field)) : app(Access::class)->requireCompany($this->user(), $permission, (string) $this->input($field));
                }
            }
        }];
    }

    /** A model the route bound (see routes/api.php), or null. */
    protected function bound(string $parameter): ?Model
    {
        $model = $this->route($parameter);

        return $model instanceof Model ? $model : null;
    }
}
