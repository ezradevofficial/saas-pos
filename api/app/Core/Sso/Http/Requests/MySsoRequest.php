<?php

namespace App\Core\Sso\Http\Requests;

use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoIdentity;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AUTH-11, ADR 023: the signed-in user's own SSO links (Settings,
 * Security): link their account to an enabled connection, or unlink one of
 * their own links. Needs no permission; only the user's own rows are reached.
 */
class MySsoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->routeIs('sso.me.link') ? [
            'connection_id' => ['required', 'uuid'],
            'origin' => ['nullable', 'string', 'max:255'],
        ] : [];
    }

    /** An enabled connection of the tenant (404 otherwise). */
    public function connection(): SsoConnection
    {
        $connection = SsoConnection::query()->whereKey($this->validated('connection_id'))->first();
        abort_if($connection === null || ! $connection->isLive(), 404);

        return $connection;
    }

    /** One of the user's own live links (404 otherwise). */
    public function identity(): SsoIdentity
    {
        $identity = $this->route('sso_identity');
        abort_if(! $identity instanceof SsoIdentity || $identity->user_id !== $this->user()->id || $identity->archived_at !== null, 404);

        return $identity;
    }
}
