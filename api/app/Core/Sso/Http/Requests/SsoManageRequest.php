<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoDomain;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-11, ADR 023: single sign-on is managed tenant-wide with
 * `core.sso.manage` (the Owner template; never the Admin's): whoever
 * controls the IdP can sign in as any linked user. A connection or domain
 * of another tenant is not found (row-level security on route binding).
 */
class SsoManageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.sso.manage', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }

    public function connection(): SsoConnection
    {
        $connection = $this->route('sso_connection');
        abort_if(! $connection instanceof SsoConnection || $connection->archived_at !== null, 404);

        return $connection;
    }

    public function domain(): SsoDomain
    {
        $domain = $this->route('sso_domain');
        abort_if(! $domain instanceof SsoDomain || $domain->archived_at !== null, 404);

        return $domain;
    }
}
