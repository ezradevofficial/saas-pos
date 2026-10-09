<?php

namespace App\Core\Configuration\Http\Requests;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * `scope_type` and `scope_id` naming where a document applies (TEN-01,
 * RBAC-04): the type must be one the kind allows (and, for copies, a
 * place); the id must be a row of this tenant (row-level security hides
 * every other tenant's rows), an active role for the role scope, and
 * empty (or the tenant's own id) for the tenant scope. Whether the user
 * may edit there is checked after validation (ConfigPolicy).
 */
trait ValidatesConfigScope
{
    /**
     * @param  list<string>  $types
     * @return array<string, list<mixed>>
     */
    protected function scopeRules(ConfigKind $kind, array $types): array
    {
        return [
            'scope_type' => ['required', 'string', Rule::in(array_values(array_intersect($types, $kind->scopes)))],
            'scope_id' => ['nullable', 'string', 'required_unless:scope_type,'.ConfigDocument::TENANT, function (string $attribute, mixed $value, Closure $fail) {
                if (! $this->scopeExists((string) $this->input('scope_type'), $value)) {
                    $fail(__('validation.exists', ['attribute' => __('config.attributes.scope_id')]));
                }
            }],
        ];
    }

    /** The scope id as stored: null for the tenant scope. */
    public function scopeId(): ?string
    {
        return $this->validated('scope_type') === ConfigDocument::TENANT ? null : $this->validated('scope_id');
    }

    private function scopeExists(string $type, mixed $id): bool
    {
        if ($type === ConfigDocument::TENANT) {
            return $id === null || $id === app(TenantContext::class)->id();
        }

        if (! is_string($id) || ! Str::isUuid($id)) {
            return false;
        }

        return match ($type) {
            ConfigDocument::COMPANY => Company::query()->whereKey($id)->exists(),
            ConfigDocument::BRANCH => Branch::query()->whereKey($id)->exists(),
            ConfigDocument::LOCATION => Location::query()->whereKey($id)->exists(),
            ConfigDocument::ROLE => Role::query()->whereKey($id)->whereNull('archived_at')->exists(),
            ConfigDocument::USER => User::query()->whereKey($id)->exists(),
            default => false,
        };
    }
}
